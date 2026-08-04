<?php
declare(strict_types=1);

/** GET /properties  — lista casas/unidades con su calle y cuota asociada */
function handle_properties_list(): void
{
    Auth::requireUser();

    $pdo = Database::connection();
    $stmt = $pdo->query(
        'SELECT p.id, p.numOficial, p.due_day, s.name AS street_name, q.name AS quota_name, q.cost AS quota_cost
         FROM property p
         LEFT JOIN street s ON s.id = p.street_id
         LEFT JOIN quota  q ON q.id = p.quota_id
         WHERE p.deleteAt IS NULL
         ORDER BY s.name, p.numOficial'
    );

    Response::json(200, ['status' => 'ok', 'data' => $stmt->fetchAll()]);
}
