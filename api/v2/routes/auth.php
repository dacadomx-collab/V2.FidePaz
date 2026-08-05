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
        'SELECT id, email, name, role, password FROM `user` WHERE email = :email AND deleteAt IS NULL LIMIT 1'
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
            'id'    => (int) $user['id'],
            'email' => $user['email'],
            'name'  => $user['name'],
            'role'  => $user['role'],
        ],
    ]);
}
