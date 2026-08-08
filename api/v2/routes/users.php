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
        $where[] = '(name LIKE :name OR email LIKE :name OR code LIKE :name)';
        $params['name'] = '%' . $_GET['name'] . '%';
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

    $pdo = Database::connection();

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM `user` WHERE ' . implode(' AND ', $where));
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $sql = 'SELECT id, email, name, code, phone, cellphone, role, createAt
            FROM `user`
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY name
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
function handle_user_update(int $id): void
{
    $claims = Auth::requireUser();
    Auth::requireRole($claims, ['admin', 'super_admin']);

    $pdo = Database::connection();
    $existing = $pdo->prepare('SELECT id, name, email, phone, cellphone, code, role FROM `user` WHERE id = ? AND deleteAt IS NULL');
    $existing->execute([$id]);
    $before = $existing->fetch();
    if ($before === false) {
        Response::error(404, 'Propietario no encontrado');
    }

    $body = json_decode(file_get_contents('php://input') ?: '', true) ?? [];
    $name = trim((string) ($body['name'] ?? ''));
    $email = trim((string) ($body['email'] ?? ''));
    $phone = trim((string) ($body['phone'] ?? '')) ?: null;
    $cellphone = trim((string) ($body['cellphone'] ?? '')) ?: null;
    $code = trim((string) ($body['code'] ?? '')) ?: null;
    $role = (string) ($body['role'] ?? $before['role']);

    if ($name === '' || $email === '') {
        Response::error(400, 'name y email son obligatorios');
    }
    if (!in_array($role, ['owner', 'admin', 'super_admin'], true)) {
        Response::error(400, 'role inválido');
    }

    $stmt = $pdo->prepare(
        'UPDATE `user` SET name = ?, email = ?, phone = ?, cellphone = ?, code = ?, role = ?, updateAt = NOW()
         WHERE id = ?'
    );
    $stmt->execute([$name, $email, $phone, $cellphone, $code, $role, $id]);

    Audit::log('user', $id, 'update', (int) $claims['sub'], ['before' => $before, 'after' => [
        'name' => $name, 'email' => $email, 'phone' => $phone, 'cellphone' => $cellphone, 'code' => $code, 'role' => $role,
    ]]);

    Response::json(200, ['status' => 'ok', 'id' => $id]);
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
