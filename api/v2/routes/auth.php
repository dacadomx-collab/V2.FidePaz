<?php
declare(strict_types=1);

/** POST /auth/login  { "email": "...", "password": "..." } */
function handle_auth_login(): void
{
    RateLimit::check('login', 20, 300); // máx 20 intentos / 5 min / IP (subido de 10 el 2026-08-05: el límite original generaba falsos positivos durante QA activo sin aportar protección real adicional contra fuerza bruta -- bcrypt cost=10 + JWT siguen siendo la defensa principal)

    $body = json_decode(file_get_contents('php://input'), true);
    $email = trim((string) ($body['email'] ?? ''));
    $password = (string) ($body['password'] ?? '');

    if ($email === '' || $password === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        Response::error(400, 'Email y contraseña son requeridos');
    }

    $pdo = Database::connection();
    $stmt = $pdo->prepare(
        'SELECT id, email, name, role, password, privacy FROM `user` WHERE email = :email AND deleteAt IS NULL LIMIT 1'
    );
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    // Mensaje idéntico si el usuario no existe o si la contraseña es incorrecta
    // (evita filtrar por respuesta si un email está registrado — enumeration attack).
    if (!$user || !password_verify($password, $user['password'])) {
        Response::error(401, 'Credenciales inválidas');
    }

    $token = Jwt::issue([
        'sub'   => (int) $user['id'],
        'email' => $user['email'],
        'role'  => $user['role'],
    ], Env::required('JWT_SECRET'), (int) Env::get('JWT_TTL_SECONDS', '3600'));

    Response::json(200, [
        'status' => 'ok',
        'token'  => $token,
        // accessToken es un alias del mismo valor de "token": el panel Angular
        // ya compilado (administrator/) lee especificamente response.accessToken
        // en su AuthService -- ver login() en el chunk 248 del bundle. Sin este
        // alias, el login HTTP 200 si ocurre pero el SPA guarda un token vacio y
        // nunca redirige al dashboard. Se conserva "token" por compatibilidad.
        'accessToken' => $token,
        'user'   => [
            'id'      => (int) $user['id'],
            'email'   => $user['email'],
            'name'    => $user['name'],
            'role'    => $user['role'],
            // 2026-08-12: agregado para que panel/mi-cuenta.html sepa si mostrar
            // el botón "Aceptar aviso de privacidad" o el recordatorio de que ya
            // se aceptó, sin necesitar un endpoint aparte -- `privacy` es
            // `TINYINT(1) NULL` en la BD, se normaliza aquí a boolean real.
            'privacy' => (bool) $user['privacy'],
        ],
    ]);
}

/**
 * POST /auth/forgot-password { "email": "..." } — Propuesta de valor #2
 * aprobada 2026-08-10 (recuperación de contraseña).
 *
 * ⚠️ NO REGISTRADA en index.php todavía. Requiere la tabla
 * `password_resets` (ver `db/migration_2026-08-10_password_resets.sql`,
 * NO ejecutada -- pendiente de autorización explícita del Arquitecto,
 * Mandamiento #9) y un envío de correo de prueba real y verificado antes
 * de activarse (ver `api/v2/core/Mailer.php`). Registrar esta ruta antes
 * de que exista la tabla haría que cada intento de "olvidé mi contraseña"
 * devolviera un 500 -- código preparado, no expuesto todavía.
 *
 * Mismo patrón anti-enumeración que /auth/login: responde 200 genérico
 * exista o no el email, para no filtrar qué correos están registrados.
 */
function handle_auth_forgot_password(): void
{
    RateLimit::check('forgot-password', 5, 900); // 5 intentos / 15 min / IP

    $body = json_decode(file_get_contents('php://input') ?: '', true) ?? [];
    $email = trim((string) ($body['email'] ?? ''));

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        Response::error(400, 'Email inválido');
    }

    $pdo = Database::connection();
    $stmt = $pdo->prepare('SELECT id, name FROM `user` WHERE email = :email AND deleteAt IS NULL LIMIT 1');
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if ($user !== false) {
        $token = bin2hex(random_bytes(32)); // 64 hex chars, nunca se persiste en claro
        $tokenHash = hash('sha256', $token);
        $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1h

        $insert = $pdo->prepare(
            'INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)'
        );
        $insert->execute([(int) $user['id'], $tokenHash, $expiresAt]);

        $resetUrl = 'https://v2.fidepaz.org/panel/reset-password.html?token=' . urlencode($token);
        Mailer::send(
            $email,
            'Recupera tu contraseña — FidePaz',
            '<p>Hola ' . htmlspecialchars((string) $user['name'], ENT_QUOTES, 'UTF-8') . ',</p>'
            . '<p>Solicitaste restablecer tu contraseña. Este enlace es válido por 1 hora:</p>'
            . '<p><a href="' . htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8') . '">Restablecer contraseña</a></p>'
            . '<p>Si no fuiste tú, ignora este correo.</p>'
        );
    }

    // Mismo mensaje exista o no el usuario -- evita enumeration attack.
    Response::json(200, ['status' => 'ok', 'message' => 'Si el correo está registrado, recibirás un enlace para restablecer tu contraseña.']);
}

/**
 * POST /auth/reset-password { "token": "...", "password": "..." } —
 * completa el flujo de la Propuesta de valor #2. Misma nota de estado que
 * arriba: NO registrada en index.php hasta que exista `password_resets`.
 */
function handle_auth_reset_password(): void
{
    $body = json_decode(file_get_contents('php://input') ?: '', true) ?? [];
    $token = (string) ($body['token'] ?? '');
    $password = (string) ($body['password'] ?? '');

    if ($token === '' || strlen($password) < 8) {
        Response::error(400, 'token es obligatorio y password debe tener al menos 8 caracteres');
    }

    $tokenHash = hash('sha256', $token);
    $pdo = Database::connection();

    $stmt = $pdo->prepare(
        'SELECT id, user_id FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1'
    );
    $stmt->execute([$tokenHash]);
    $reset = $stmt->fetch();

    if ($reset === false) {
        Response::error(400, 'El enlace ya expiró o ya fue usado. Solicita uno nuevo.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE `user` SET password = ?, updateAt = NOW() WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_BCRYPT), (int) $reset['user_id']]);
        $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?')
            ->execute([(int) $reset['id']]);
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    Response::json(200, ['status' => 'ok', 'message' => 'Contraseña actualizada. Ya puedes iniciar sesión.']);
}
