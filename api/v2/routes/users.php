<?php
declare(strict_types=1);

/** GET /users — solo roles administrativos; nunca expone la columna `password`. */
function handle_users_list(): void
{
    $claims = Auth::requireUser();
    Auth::requireRole($claims, ['admin', 'super_admin']);

    $pdo = Database::connection();
    $stmt = $pdo->query(
        'SELECT id, email, name, code, phone, cellphone, role, createAt
         FROM `user`
         WHERE deleteAt IS NULL
         ORDER BY name'
    );

    $rows = $stmt->fetchAll();
    // "items" (+ "meta") es alias de "data" -- ver nota en properties.php,
    // mismo bug/fix: el panel espera response.items / response.meta.total.
    Response::json(200, ['status' => 'ok', 'data' => $rows, 'items' => $rows, 'meta' => ['total' => count($rows)]]);
}

/**
 * GET /user/filter?page=&name=
 *
 * Endpoint REAL usado por la pantalla "Propietarios" (confirmado
 * decompilando userService.filterOwners en main.<hash>.js -- llama a
 * `${basePath}/user/filter`, no a `/users`). La tabla (994.<hash>.js) lee
 * campos planos (name/email/phone/cellphone), pero SÍ exige
 * `response.items` + `response.meta.totalPages` con paginación real.
 */
function handle_users_filter(): void
{
    $claims = Auth::requireUser();
    Auth::requireRole($claims, ['admin', 'super_admin']);

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $pageSize = 20;
    $offset = ($page - 1) * $pageSize;

    $where = ['deleteAt IS NULL'];
    $params = [];
    if (!empty($_GET['name'])) {
        // 3 placeholders distintos, no ":name" repetido 3 veces: PDO con
        // prepares reales (PDO::ATTR_EMULATE_PREPARES=false) no expande un
        // nombre de parámetro repetido a cada aparición -- solo la primera
        // queda ligada, el resto revienta con "Invalid parameter number"
        // (bug real encontrado 2026-08-08: /user/filter daba 500 en
        // cualquier búsqueda por nombre).
        $where[] = '(name LIKE :name1 OR email LIKE :name2 OR code LIKE :name3)';
        $term = '%' . $_GET['name'] . '%';
        $params['name1'] = $term;
        $params['name2'] = $term;
        $params['name3'] = $term;
    }
    // Filtro por código de propiedad/colono aparte (2026-08-07): búsqueda
    // multicampo exacta, distinta del LIKE combinado de arriba.
    if (!empty($_GET['code'])) {
        $where[] = 'code LIKE :code';
        $params['code'] = '%' . $_GET['code'] . '%';
    }
    if (!empty($_GET['role']) && in_array($_GET['role'], ['owner', 'admin', 'super_admin'], true)) {
        $where[] = 'role = :role';
        $params['role'] = $_GET['role'];
    }

    // Orden por encabezado de columna (2026-08-08, panel nuevo): whitelist
    // estricta de columna -- nunca se interpola el valor crudo de $_GET en
    // el SQL, solo se usa para elegir entre literales fijos conocidos.
    $sortColumns = ['name' => 'name', 'email' => 'email', 'code' => 'code', 'role' => 'role', 'createAt' => 'createAt'];
    $sortKey = $sortColumns[$_GET['sortKey'] ?? ''] ?? 'name';
    $sortDir = (($_GET['sortDir'] ?? '') === 'desc') ? 'DESC' : 'ASC';

    $pdo = Database::connection();

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM `user` WHERE ' . implode(' AND ', $where));
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    // rfc/contactName/contactPhone se agregan al SELECT (2026-08-08): el
    // panel nuevo prellena el formulario de edición con la fila ya cargada
    // en la tabla (no existe un GET /user/{id} real) -- sin estas columnas
    // el formulario los mandaría vacíos en cada edición, borrando el dato
    // real en la base con cada PUT /user/update/{id}.
    $sql = 'SELECT id, email, name, code, phone, cellphone, role, createAt, rfc, contactName, contactPhone
            FROM `user`
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY ' . $sortKey . ' ' . $sortDir . '
            LIMIT :limit OFFSET :offset';
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue('limit', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    Response::json(200, [
        'status' => 'ok',
        'items' => $rows,
        'meta' => [
            'total' => $total,
            'totalPages' => (int) ceil($total / $pageSize),
            'page' => $page,
        ],
    ]);
}

/**
 * GET /user/byterm?search=
 *
 * Autocompletado de propietario (`userService.getUsersByTerm`, usado por
 * `ng-select` en el formulario de Propiedades para asignar dueño). Igual
 * que `/property/streets` y `/quota/byterm`: array plano en la raíz.
 */
function handle_user_byterm(): void
{
    Auth::requireUser();

    $pdo = Database::connection();
    if (!empty($_GET['search'])) {
        $stmt = $pdo->prepare(
            'SELECT id, name, email, code FROM `user` WHERE deleteAt IS NULL AND name LIKE :search ORDER BY name'
        );
        $stmt->bindValue('search', '%' . $_GET['search'] . '%');
        $stmt->execute();
    } else {
        $stmt = $pdo->query('SELECT id, name, email, code FROM `user` WHERE deleteAt IS NULL ORDER BY name');
    }
    $rows = $stmt->fetchAll();

    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($rows, JSON_UNESCAPED_UNICODE);
    exit;
}

/** GET /catalog/streets y /catalog/quotas — catálogos de solo lectura para llenar selects del panel. */
function handle_catalog(string $which): void
{
    Auth::requireUser();

    // Nota de compatibilidad: sin "match" (PHP 8.0+) — este cPanel corre PHP 7.4.
    switch ($which) {
        case 'streets':
            $table = 'street';
            break;
        case 'quotas':
            $table = 'quota';
            break;
        default:
            $table = null;
    }
    if ($table === null) {
        Response::error(404, 'Catálogo no encontrado');
    }

    $pdo = Database::connection();
    $stmt = $pdo->query("SELECT * FROM `{$table}` ORDER BY id");
    $rows = $stmt->fetchAll();
    // Mismo alias preventivo que en el resto de rutas de lista (ver nota en
    // properties.php) -- no confirmado como roto, pero mismo patrón de riesgo.
    Response::json(200, ['status' => 'ok', 'data' => $rows, 'items' => $rows, 'meta' => ['total' => count($rows)]]);
}

/**
 * PUT /user/update-privacy — el colono autenticado acepta el aviso de
 * privacidad la primera vez que entra a "Mi estado de cuenta"
 * (`app-owners-resume.acceptPrivacy()`, muestra un modal bloqueante hasta
 * que `user.privacy` sea verdadero). Sin body -- siempre marca al usuario
 * del JWT como aceptado, nunca a otro.
 */
function handle_user_update_privacy(): void
{
    $claims = Auth::requireUser();
    $userId = (int) $claims['sub'];

    $pdo = Database::connection();
    $stmt = $pdo->prepare('UPDATE `user` SET privacy = 1 WHERE id = ?');
    $stmt->execute([$userId]);

    Response::json(200, ['status' => 'ok']);
}

/**
 * PUT /user/{id} — editar un colono (admin/super_admin).
 * Campos reales editables: name, email, phone, cellphone, code, role.
 * "Estatus" no es una columna aparte en `user` -- se maneja con
 * `deleteAt` (soft-delete, ver DELETE abajo) y con `role` para permisos.
 */
/**
 * PUT /user/update/{id} — editar colono (admin/super_admin).
 *
 * Ruta y payload corregidos 2026-08-08: el formulario real de edición
 * (`app-edit-users`) manda `{rfc,name,email,phone,cellphone,contactPhone,
 * contactName,password?,c_password?}` a `PUT /user/update/{id}` — NO
 * `{name,email,phone,cellphone,code,role}` a `/user/{id}` como se había
 * asumido antes sin verificar. `code` y `role` no son editables desde
 * este formulario (no aparecen en él) — se conservan sin tocar.
 * `password` es opcional: si se manda, se re-hashea con bcrypt; si no,
 * el password actual no cambia.
 */
function handle_user_update(int $id): void
{
    $claims = Auth::requireUser();
    Auth::requireRole($claims, ['admin', 'super_admin']);

    $pdo = Database::connection();
    $existing = $pdo->prepare(
        'SELECT id, name, email, phone, cellphone, rfc, contactName, contactPhone FROM `user` WHERE id = ? AND deleteAt IS NULL'
    );
    $existing->execute([$id]);
    $before = $existing->fetch();
    if ($before === false) {
        Response::error(404, 'Propietario no encontrado');
    }

    $body = json_decode(file_get_contents('php://input') ?: '', true) ?? [];
    $name = trim((string) ($body['name'] ?? ''));
    $email = trim((string) ($body['email'] ?? ''));
    $phone = array_key_exists('phone', $body) ? (trim((string) $body['phone']) ?: null) : $before['phone'];
    $cellphone = array_key_exists('cellphone', $body) ? (trim((string) $body['cellphone']) ?: null) : $before['cellphone'];
    $rfc = array_key_exists('rfc', $body) ? (trim((string) $body['rfc']) ?: null) : $before['rfc'];
    $contactName = array_key_exists('contactName', $body) ? (trim((string) $body['contactName']) ?: null) : $before['contactName'];
    $contactPhone = array_key_exists('contactPhone', $body) ? (trim((string) $body['contactPhone']) ?: null) : $before['contactPhone'];
    $password = trim((string) ($body['password'] ?? ''));

    if ($name === '' || $email === '') {
        Response::error(400, 'name y email son obligatorios');
    }
    if ($password !== '' && strlen($password) < 6) {
        Response::error(400, 'password debe tener al menos 6 caracteres');
    }

    if ($password !== '') {
        $stmt = $pdo->prepare(
            'UPDATE `user` SET name=?, email=?, phone=?, cellphone=?, rfc=?, contactName=?, contactPhone=?, password=?, updateAt=NOW()
             WHERE id = ?'
        );
        $stmt->execute([$name, $email, $phone, $cellphone, $rfc, $contactName, $contactPhone, password_hash($password, PASSWORD_BCRYPT), $id]);
    } else {
        $stmt = $pdo->prepare(
            'UPDATE `user` SET name=?, email=?, phone=?, cellphone=?, rfc=?, contactName=?, contactPhone=?, updateAt=NOW()
             WHERE id = ?'
        );
        $stmt->execute([$name, $email, $phone, $cellphone, $rfc, $contactName, $contactPhone, $id]);
    }

    Audit::log('user', $id, 'update', (int) $claims['sub'], ['before' => $before, 'after' => [
        'name' => $name, 'email' => $email, 'phone' => $phone, 'cellphone' => $cellphone,
        'rfc' => $rfc, 'contactName' => $contactName, 'contactPhone' => $contactPhone,
        'passwordChanged' => $password !== '',
    ]]);

    Response::json(200, ['status' => 'ok', 'id' => $id]);
}

/**
 * POST /user/create — crear colono (admin/super_admin).
 * Payload real del formulario: {rfc,name,email,password,phone,cellphone,
 * contactPhone,contactName} (c_password se valida solo en el cliente).
 *
 * `role` ampliado 2026-08-10 (panel nuevo, `panel/propietarios.html`):
 * whitelist estricta `owner`|`admin`|`super_admin` (los 3 roles reales del
 * sistema -- ver 02_CODEX_Y_SCHEMA_MAESTRO.md; no existe ni se acepta un
 * rol "operator", no tiene ningún soporte en el resto del contrato de 49
 * rutas y crearía cuentas que no pasan ningún `Auth::requireRole()` real).
 * Default `owner` si se omite, igual que antes.
 */
function handle_user_create(): void
{
    $claims = Auth::requireUser();
    Auth::requireRole($claims, ['admin', 'super_admin']);

    $body = json_decode(file_get_contents('php://input') ?: '', true) ?? [];
    $name = trim((string) ($body['name'] ?? ''));
    $email = trim((string) ($body['email'] ?? ''));
    $password = (string) ($body['password'] ?? '');
    $phone = trim((string) ($body['phone'] ?? '')) ?: null;
    $cellphone = trim((string) ($body['cellphone'] ?? '')) ?: null;
    $rfc = trim((string) ($body['rfc'] ?? '')) ?: null;
    $contactName = trim((string) ($body['contactName'] ?? '')) ?: null;
    $contactPhone = trim((string) ($body['contactPhone'] ?? '')) ?: null;
    $allowedRoles = ['owner', 'admin', 'super_admin'];
    $role = in_array($body['role'] ?? '', $allowedRoles, true) ? $body['role'] : 'owner';

    if ($name === '' || $email === '') {
        Response::error(400, 'name y email son obligatorios');
    }
    if (strlen($password) < 6) {
        Response::error(400, 'password debe tener al menos 6 caracteres');
    }

    $pdo = Database::connection();
    $dup = $pdo->prepare('SELECT id FROM `user` WHERE email = ? AND deleteAt IS NULL');
    $dup->execute([$email]);
    if ($dup->fetch() !== false) {
        Response::error(400, 'Ya existe un colono con ese correo');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO `user` (name, email, password, phone, cellphone, rfc, contactName, contactPhone, role, createAt)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
    );
    $stmt->execute([$name, $email, password_hash($password, PASSWORD_BCRYPT), $phone, $cellphone, $rfc, $contactName, $contactPhone, $role]);
    $id = (int) $pdo->lastInsertId();

    Audit::log('user', $id, 'create', (int) $claims['sub'], ['after' => ['name' => $name, 'email' => $email, 'role' => $role]]);

    Response::json(201, [
        'status' => 'ok',
        'message' => 'Usuario creado correctamente',
        'id' => $id,
        'item' => ['id' => $id, 'name' => $name, 'email' => $email, 'role' => $role],
    ]);
}

/**
 * PUT /user/reset-password/{id} { "password"?: "...", "generate"?: true }
 * (admin/super_admin) — Tarea aprobada 2026-08-10: restablecer la
 * contraseña de cualquier usuario desde la tabla de Propietarios, sin
 * pasar por el formulario completo de edición. Si `password` viene en el
 * body se usa esa (mín. 8 caracteres); si no, o si `generate:true`, se
 * genera una clave temporal aleatoria segura del lado del servidor y se
 * devuelve UNA vez en la respuesta (`generatedPassword`) -- nunca se
 * guarda en texto plano, solo su hash BCrypt, igual que cualquier otra
 * contraseña del sistema. El admin es responsable de comunicarla al
 * colono por un canal seguro; el sistema no la vuelve a mostrar después
 * de esta respuesta.
 */
function handle_user_reset_password(int $id): void
{
    $claims = Auth::requireUser();
    Auth::requireRole($claims, ['admin', 'super_admin']);

    $pdo = Database::connection();
    $existing = $pdo->prepare('SELECT id, name, email FROM `user` WHERE id = ? AND deleteAt IS NULL');
    $existing->execute([$id]);
    $user = $existing->fetch();
    if ($user === false) {
        Response::error(404, 'Propietario no encontrado');
    }

    $body = json_decode(file_get_contents('php://input') ?: '', true) ?? [];
    $password = (string) ($body['password'] ?? '');
    $generated = null;

    if ($password === '' || !empty($body['generate'])) {
        // 12 caracteres, alfabeto sin ambigüedad visual (sin 0/O/1/l/I).
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $generated = '';
        for ($i = 0; $i < 12; $i++) {
            $generated .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $password = $generated;
    }

    if (strlen($password) < 8) {
        Response::error(400, 'password debe tener al menos 8 caracteres');
    }

    $pdo->prepare('UPDATE `user` SET password = ?, updateAt = NOW() WHERE id = ?')
        ->execute([password_hash($password, PASSWORD_BCRYPT), $id]);

    // Nunca se guarda la contraseña (generada o provista) en el detalle de
    // auditoría -- solo el hecho de que ocurrió un reseteo y quién lo hizo.
    Audit::log('user', $id, 'update', (int) $claims['sub'], [
        'after' => ['passwordReset' => true, 'resetBy' => (int) $claims['sub']],
    ]);

    Response::json(200, [
        'status' => 'ok',
        'message' => 'Contraseña actualizada correctamente',
        'generatedPassword' => $generated,
    ]);
}

/**
 * DELETE /user/{id} — baja de un colono (admin/super_admin).
 * Soft-delete (`deleteAt = NOW()`), consistente con el resto de la API
 * (`WHERE deleteAt IS NULL` en todas las queries activas) -- un borrado
 * físico rompería la integridad histórica de `user_quotas` (pagos ya
 * registrados a nombre de ese colono).
 */
function handle_user_delete(int $id): void
{
    $claims = Auth::requireUser();
    Auth::requireRole($claims, ['admin', 'super_admin']);

    $pdo = Database::connection();
    $existing = $pdo->prepare('SELECT id, name, email FROM `user` WHERE id = ? AND deleteAt IS NULL');
    $existing->execute([$id]);
    $before = $existing->fetch();
    if ($before === false) {
        Response::error(404, 'Propietario no encontrado');
    }

    $stmt = $pdo->prepare('UPDATE `user` SET deleteAt = NOW() WHERE id = ?');
    $stmt->execute([$id]);

    Audit::log('user', $id, 'delete', (int) $claims['sub'], ['before' => $before]);

    Response::json(200, ['status' => 'ok']);
}

/** GET /user/{id}/history — bitácora de cambios de un colono (admin/super_admin). */
function handle_user_history(int $id): void
{
    $claims = Auth::requireUser();
    Auth::requireRole($claims, ['admin', 'super_admin']);

    Response::json(200, ['status' => 'ok', 'items' => Audit::history('user', $id)]);
}
