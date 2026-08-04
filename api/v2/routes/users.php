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

    Response::json(200, ['status' => 'ok', 'data' => $stmt->fetchAll()]);
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
    Response::json(200, ['status' => 'ok', 'data' => $stmt->fetchAll()]);
}
