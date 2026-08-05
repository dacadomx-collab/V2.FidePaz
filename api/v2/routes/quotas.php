<?php
declare(strict_types=1);

/**
 * GET /user-quotas?property_id=123&from=2026-01-01&to=2026-12-31&status=1
 * Todos los filtros son opcionales; usa los índices compuestos de schema.sql.
 */
function handle_quotas_list(): void
{
    $claims = Auth::requireUser();

    $where = ['1=1'];
    $params = [];

    // Un colono (role=owner) solo puede ver sus propias cuotas; solo admin/super_admin pueden filtrar por otro user_id.
    if (($claims['role'] ?? '') === 'owner') {
        $where[] = 'uq.user_id = :own_user_id';
        $params['own_user_id'] = (int) $claims['sub'];
    } elseif (!empty($_GET['user_id'])) {
        $where[] = 'uq.user_id = :user_id';
        $params['user_id'] = (int) $_GET['user_id'];
    }

    if (!empty($_GET['property_id'])) {
        $where[] = 'uq.property_id = :property_id';
        $params['property_id'] = (int) $_GET['property_id'];
    }
    if (!empty($_GET['status'])) {
        $where[] = 'uq.status = :status';
        $params['status'] = (int) $_GET['status'];
    }
    if (!empty($_GET['from'])) {
        $where[] = 'uq.due_date >= :from';
        $params['from'] = $_GET['from'];
    }
    if (!empty($_GET['to'])) {
        $where[] = 'uq.due_date <= :to';
        $params['to'] = $_GET['to'];
    }

    $limit = max(1, min(200, (int) ($_GET['limit'] ?? 50)));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));

    $sql = 'SELECT uq.id, uq.due_date, uq.pay_date, uq.status, uq.amount, uq.receipt,
                   uq.user_id, u.name AS user_name, uq.property_id, p.numOficial
            FROM user_quotas uq
            LEFT JOIN `user` u ON u.id = uq.user_id
            LEFT JOIN property p ON p.id = uq.property_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY uq.due_date DESC
            LIMIT :limit OFFSET :offset';

    $pdo = Database::connection();
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll();
    // "items" (+ "meta") es alias de "data" -- ver nota en properties.php,
    // mismo bug/fix: el panel espera response.items / response.meta.total.
    Response::json(200, ['status' => 'ok', 'data' => $rows, 'items' => $rows, 'meta' => ['total' => count($rows), 'limit' => $limit, 'offset' => $offset]]);
}
