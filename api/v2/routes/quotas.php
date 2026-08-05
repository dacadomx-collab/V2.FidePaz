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

/**
 * GET /quota?page=
 *
 * Endpoint REAL usado por la pantalla "Cuotas" (confirmado decompilando
 * quotaControllerGetAll en main.<hash>.js -- llama a `${basePath}/quota`,
 * ruta que NO existía en el router; por eso el menú "Cuotas" devolvía 404
 * y se veía vacío pese a que /user-quotas sí respondía datos).
 */
function handle_quota_catalog_list(): void
{
    Auth::requireUser();

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $pageSize = 20;
    $offset = ($page - 1) * $pageSize;

    $pdo = Database::connection();
    $total = (int) $pdo->query('SELECT COUNT(*) FROM quota')->fetchColumn();

    $stmt = $pdo->prepare('SELECT id, name, cost FROM quota ORDER BY id LIMIT :limit OFFSET :offset');
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
 * GET /payment/list-owners?page=&search=&streets[]=&initDate=&endDate=
 *
 * Ruta REAL de la pantalla "Pagos" (confirmado decompilando 964.<hash>.js,
 * componente app-owner-state/app-list-reports -- llama a
 * `${basePath}/payment/list-owners`). El template hace su propio cálculo de
 * "pagado" vs "pendiente" sumando `quotas[].amount` según `status`, así que
 * este endpoint entrega los datos crudos (cuotas anidadas por colono, con
 * calle) y los totales agregados que la vista lee directo del payload
 * (`totalToPay`, `totalPaid`) — no adivina esos números, los calcula igual
 * que la vista: status=1 → pendiente, status=2 → pagado (ver
 * 02_CODEX_Y_SCHEMA_MAESTRO.md, mapeo verificado contra datos reales).
 */
function handle_payment_list_owners(): void
{
    Auth::requireUser();

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $pageSize = 20;
    $offset = ($page - 1) * $pageSize;

    $where = ['deleteAt IS NULL'];
    $params = [];
    if (!empty($_GET['search'])) {
        $where[] = 'name LIKE :search';
        $params['search'] = '%' . $_GET['search'] . '%';
    }

    $pdo = Database::connection();

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM `user` WHERE ' . implode(' AND ', $where));
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $ownerStmt = $pdo->prepare(
        'SELECT id, name, code FROM `user` WHERE ' . implode(' AND ', $where) . '
         ORDER BY name LIMIT :limit OFFSET :offset'
    );
    foreach ($params as $key => $value) {
        $ownerStmt->bindValue($key, $value);
    }
    $ownerStmt->bindValue('limit', $pageSize, PDO::PARAM_INT);
    $ownerStmt->bindValue('offset', $offset, PDO::PARAM_INT);
    $ownerStmt->execute();
    $owners = $ownerStmt->fetchAll();

    $ownerIds = array_column($owners, 'id');
    $quotasByOwner = [];
    $totalToPay = 0.0;
    $totalPaid = 0.0;

    if (!empty($ownerIds)) {
        $inPlaceholders = implode(',', array_fill(0, count($ownerIds), '?'));
        $qParams = $ownerIds;
        $dateWhere = '';
        if (!empty($_GET['initDate'])) {
            $dateWhere .= ' AND uq.due_date >= ?';
            $qParams[] = substr((string) $_GET['initDate'], 0, 10);
        }
        if (!empty($_GET['endDate'])) {
            $dateWhere .= ' AND uq.due_date <= ?';
            $qParams[] = substr((string) $_GET['endDate'], 0, 10);
        }

        $qStmt = $pdo->prepare(
            "SELECT uq.id, uq.status, uq.amount, uq.due_date, uq.pay_date, uq.user_id,
                    p.id AS property_id, p.numOficial, s.name AS street_name
             FROM user_quotas uq
             LEFT JOIN property p ON p.id = uq.property_id
             LEFT JOIN street s ON s.id = p.street_id
             WHERE uq.user_id IN ({$inPlaceholders}){$dateWhere}
             ORDER BY uq.due_date DESC"
        );
        $qStmt->execute($qParams);
        foreach ($qStmt->fetchAll() as $q) {
            $amount = (float) $q['amount'];
            if ((int) $q['status'] === 2) {
                $totalPaid += $amount;
            } else {
                $totalToPay += $amount;
            }
            $quotasByOwner[(int) $q['user_id']][] = [
                'id' => $q['id'],
                'status' => $q['status'],
                'amount' => $q['amount'],
                'dueDate' => $q['due_date'],
                'payDate' => $q['pay_date'],
                'property' => [
                    'id' => $q['property_id'],
                    'numOficial' => $q['numOficial'],
                    'street' => ['name' => $q['street_name']],
                    'extras' => [],
                ],
            ];
        }
    }

    $items = array_map(static function (array $o) use ($quotasByOwner): array {
        return [
            'id' => $o['id'],
            'name' => $o['name'],
            'code' => $o['code'],
            'quotas' => $quotasByOwner[(int) $o['id']] ?? [],
        ];
    }, $owners);

    Response::json(200, [
        'status' => 'ok',
        'items' => $items,
        'totalToPay' => round($totalToPay, 2),
        'totalPaid' => round($totalPaid, 2),
        'meta' => [
            'total' => $total,
            'totalPages' => (int) ceil($total / $pageSize),
            'page' => $page,
        ],
    ]);
}

/**
 * GET /payment?page=&status=&streets[]=&initDate=&endDate=&code=
 *
 * Ruta REAL de la pantalla "Pagos" (app-list-payments, chunk 104 del
 * bundle compilado -- confirmado por `paymentService.getAllPayments()` en
 * `ngOnInit()`, llamada en la carga inicial de la página, distinta de
 * `/payment/list-owners` que agrupa por propietario). Lista plana de pagos
 * individuales (una fila = un `user_quotas`).
 */
function handle_payments_list(): void
{
    Auth::requireUser();

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $pageSize = 20;
    $offset = ($page - 1) * $pageSize;

    $where = ['1=1'];
    $params = [];

    if (!empty($_GET['status'])) {
        $where[] = 'uq.status = :status';
        $params['status'] = (int) $_GET['status'];
    }
    if (!empty($_GET['initDate'])) {
        $where[] = 'uq.due_date >= :initDate';
        $params['initDate'] = substr((string) $_GET['initDate'], 0, 10);
    }
    if (!empty($_GET['endDate'])) {
        $where[] = 'uq.due_date <= :endDate';
        $params['endDate'] = substr((string) $_GET['endDate'], 0, 10);
    }
    if (!empty($_GET['code'])) {
        $where[] = 'u.code LIKE :code';
        $params['code'] = '%' . $_GET['code'] . '%';
    }
    if (!empty($_GET['streets']) && is_array($_GET['streets'])) {
        $ids = array_map('intval', $_GET['streets']);
        $placeholders = [];
        foreach ($ids as $i => $id) {
            $key = "street_{$i}";
            $placeholders[] = ":{$key}";
            $params[$key] = $id;
        }
        $where[] = 'p.street_id IN (' . implode(',', $placeholders) . ')';
    }

    $pdo = Database::connection();

    $countStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM user_quotas uq
         LEFT JOIN `user` u ON u.id = uq.user_id
         LEFT JOIN property p ON p.id = uq.property_id
         WHERE ' . implode(' AND ', $where)
    );
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $sql = 'SELECT uq.id, uq.status, uq.amount, uq.due_date, uq.pay_date, uq.receipt,
                   u.id AS user_id, u.name AS user_name, u.code AS user_code,
                   p.id AS property_id, p.numOficial, s.name AS street_name
            FROM user_quotas uq
            LEFT JOIN `user` u ON u.id = uq.user_id
            LEFT JOIN property p ON p.id = uq.property_id
            LEFT JOIN street s ON s.id = p.street_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY uq.due_date DESC
            LIMIT :limit OFFSET :offset';
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue('limit', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $items = array_map(static function (array $r): array {
        return [
            'id' => $r['id'],
            'status' => $r['status'],
            'amount' => $r['amount'],
            'dueDate' => $r['due_date'],
            'payDate' => $r['pay_date'],
            'receipt' => $r['receipt'],
            'user' => ['id' => $r['user_id'], 'name' => $r['user_name'], 'code' => $r['user_code']],
            'property' => [
                'id' => $r['property_id'],
                'numOficial' => $r['numOficial'],
                'street' => ['name' => $r['street_name']],
            ],
        ];
    }, $stmt->fetchAll());

    Response::json(200, [
        'status' => 'ok',
        'items' => $items,
        'meta' => [
            'total' => $total,
            'totalPages' => (int) ceil($total / $pageSize),
            'page' => $page,
        ],
    ]);
}
