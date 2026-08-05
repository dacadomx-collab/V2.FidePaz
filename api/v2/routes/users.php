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
