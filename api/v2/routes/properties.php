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

    $rows = $stmt->fetchAll();
    // "items" (+ "meta") es alias de "data": el panel Angular ya compilado
    // lee response.items / response.meta.total en su lista de propiedades
    // (ver listProperties = r.items en el bundle) -- sin este alias la
    // sección quedaba vacía aunque la API sí traía datos reales. Se
    // conserva "data" por compatibilidad con otros consumidores/pruebas.
    Response::json(200, ['status' => 'ok', 'data' => $rows, 'items' => $rows, 'meta' => ['total' => count($rows)]]);
}
