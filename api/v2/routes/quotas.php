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

    $rows = array_map(static function (array $r): array {
        $r['amount'] = (float) $r['amount'];
        $r['due_date'] = Response::isoDate($r['due_date']);
        $r['pay_date'] = Response::isoDate($r['pay_date']);
        return $r;
    }, $stmt->fetchAll());
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
    $rows = array_map(static function (array $r): array {
        $r['cost'] = (float) $r['cost'];
        return $r;
    }, $stmt->fetchAll());

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
                'amount' => $amount,
                'dueDate' => Response::isoDate($q['due_date']),
                'payDate' => Response::isoDate($q['pay_date']),
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
 * GET /quota/byterm?search=
 *
 * Autocompletado de tipo de cuota (`quotaService.getQuotaByTerm`, usado
 * por `ng-select` en el formulario de Propiedades). Igual que
 * `/property/streets`: array plano en la raíz, no `{status,items,meta}`
 * -- el bundle asigna `this.searchResultsQuota = n` directo.
 */
function handle_quota_byterm(): void
{
    Auth::requireUser();

    $pdo = Database::connection();
    if (!empty($_GET['search'])) {
        $stmt = $pdo->prepare('SELECT id, name, cost FROM quota WHERE name LIKE :search ORDER BY name');
        $stmt->bindValue('search', '%' . $_GET['search'] . '%');
        $stmt->execute();
    } else {
        $stmt = $pdo->query('SELECT id, name, cost FROM quota ORDER BY name');
    }
    $rows = array_map(static function (array $r): array {
        $r['cost'] = (float) $r['cost'];
        return $r;
    }, $stmt->fetchAll());

    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($rows, JSON_UNESCAPED_UNICODE);
    exit;
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
    // Búsqueda unificada (2026-08-09): un solo término, busca a la vez en
    // código, nombre del colono, nombre de calle y número oficial. Cada
    // ocurrencia usa su propio placeholder (:q1.._q4) -- no ":q" repetido
    // (mismo bug real corregido en /user/filter y /property/filter,
    // ver BITACORA_HITOS_Y_LOGROS.md). Aditiva: no reemplaza los filtros
    // específicos de arriba, solo da una alternativa más simple.
    if (!empty($_GET['q'])) {
        $where[] = '(u.code LIKE :q1 OR u.name LIKE :q2 OR s.name LIKE :q3 OR p.numOficial LIKE :q4)';
        $term = '%' . $_GET['q'] . '%';
        $params['q1'] = $term;
        $params['q2'] = $term;
        $params['q3'] = $term;
        $params['q4'] = $term;
    }

    // Orden por encabezado de columna (2026-08-10, panel nuevo): whitelist
    // estricta, aditivo sobre el orden legado (`due_date DESC`) -- si no
    // viene sortKey, el bundle Angular viejo sigue viendo lo mismo de
    // siempre.
    $sortColumns = [
        'dueDate' => 'uq.due_date',
        'amount' => 'uq.amount',
        'status' => 'uq.status',
        'user' => 'u.name',
        'street' => 's.name',
        'numOficial' => 'p.numOficial',
    ];
    $sortKey = $sortColumns[$_GET['sortKey'] ?? ''] ?? null;
    $sortDir = (($_GET['sortDir'] ?? '') === 'asc') ? 'ASC' : 'DESC';
    $orderBy = $sortKey ? ($sortKey . ' ' . $sortDir) : 'uq.due_date DESC';

    $pdo = Database::connection();

    $countStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM user_quotas uq
         LEFT JOIN `user` u ON u.id = uq.user_id
         LEFT JOIN property p ON p.id = uq.property_id
         LEFT JOIN street s ON s.id = p.street_id
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
            ORDER BY ' . $orderBy . '
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
            'amount' => (float) $r['amount'],
            'dueDate' => Response::isoDate($r['due_date']),
            'payDate' => Response::isoDate($r['pay_date']),
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

/**
 * GET /payment/download-report?search=&streets[]=&initDate=&endDate=
 *
 * Botón "Descargar" de la pantalla "Reportes" (`app-list-reports.
 * downloadXLSX()`, chunk 964 -- confirmado: pide `responseType:"blob"` y
 * arma el nombre `Reporte-DD-MM-YYYY.xlsx` en el cliente). Mismas columnas
 * que la tabla en pantalla: Propietario, Código, Dirección, Pagado, Deuda.
 */
function handle_payment_download_report(): void
{
    Auth::requireUser();

    $where = ['1=1'];
    $params = [];
    if (!empty($_GET['search'])) {
        $where[] = 'u.name LIKE :search';
        $params['search'] = '%' . $_GET['search'] . '%';
    }
    if (!empty($_GET['initDate'])) {
        $where[] = 'uq.due_date >= :initDate';
        $params['initDate'] = substr((string) $_GET['initDate'], 0, 10);
    }
    if (!empty($_GET['endDate'])) {
        $where[] = 'uq.due_date <= :endDate';
        $params['endDate'] = substr((string) $_GET['endDate'], 0, 10);
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
    $stmt = $pdo->prepare(
        'SELECT u.id, u.name, u.code,
                SUM(CASE WHEN uq.status = 2 THEN uq.amount ELSE 0 END) AS paid,
                SUM(CASE WHEN uq.status <> 2 THEN uq.amount ELSE 0 END) AS due
         FROM `user` u
         LEFT JOIN user_quotas uq ON uq.user_id = u.id
         LEFT JOIN property p ON p.id = uq.property_id
         WHERE u.deleteAt IS NULL AND (' . implode(' AND ', $where) . ')
         GROUP BY u.id, u.name, u.code
         ORDER BY u.name'
    );
    $stmt->execute($params);

    $rows = [['Propietario', 'Código', 'Pagado', 'Deuda']];
    foreach ($stmt->fetchAll() as $r) {
        $rows[] = [$r['name'], $r['code'] ?? 'No Asignado', number_format((float) $r['paid'], 2), number_format((float) $r['due'], 2)];
    }

    Xlsx::send(Xlsx::build($rows), 'Reporte-' . date('d-m-Y') . '.xlsx');
}

/**
 * GET /payment/download-report-state/{id}?initDate=&endDate=
 *
 * Botón "Descargar" de la pantalla "Estado del propietario"
 * (`app-owner-state.downloadXLSX()`). Mismas columnas que la tabla en
 * pantalla: Fecha de vencimiento, Monto, Estado, Fecha de pago.
 */
function handle_payment_download_report_state(int $userId): void
{
    Auth::requireUser();

    $where = ['uq.user_id = :user_id'];
    $params = ['user_id' => $userId];
    if (!empty($_GET['initDate'])) {
        $where[] = 'uq.due_date >= :initDate';
        $params['initDate'] = substr((string) $_GET['initDate'], 0, 10);
    }
    if (!empty($_GET['endDate'])) {
        $where[] = 'uq.due_date <= :endDate';
        $params['endDate'] = substr((string) $_GET['endDate'], 0, 10);
    }

    $pdo = Database::connection();
    $userStmt = $pdo->prepare('SELECT name, code FROM `user` WHERE id = ?');
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch();
    if ($user === false) {
        Response::error(404, 'Propietario no encontrado');
    }

    $stmt = $pdo->prepare(
        'SELECT due_date, amount, status, pay_date FROM user_quotas uq
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY due_date DESC'
    );
    $stmt->execute($params);

    $rows = [['Fecha de vencimiento', 'Monto', 'Estado', 'Fecha de pago']];
    foreach ($stmt->fetchAll() as $r) {
        $rows[] = [
            substr((string) $r['due_date'], 0, 10),
            number_format((float) $r['amount'], 2),
            ((int) $r['status'] === 2) ? 'Pagado' : 'No Pagado',
            $r['pay_date'] !== null ? substr((string) $r['pay_date'], 0, 10) : 'No Ingresado',
        ];
    }

    $filename = 'Reporte-' . $user['name'] . '-' . ($user['code'] ?? '') . '.xlsx';
    Xlsx::send(Xlsx::build($rows), $filename);
}

/**
 * GET /payment/quotas-owners/{id}?page=&initDate=&endDate=
 *
 * Ruta REAL de la pantalla "Estado del propietario" (`app-owner-state`,
 * children de `app-list-reports`, chunk 964 -- confirmado: al hacer click
 * en una fila de "Reportes", `goOwner()` navega a `/home/reports/state-owner`
 * pasando el propietario completo como `router state` (sin llamar a la API);
 * es ESA pantalla la que sí llama a `paymentService.getPaymentsByUserAndDate
 * (id, page, initDate, endDate)` → esta ruta. La tabla que consume la
 * respuesta muestra: Fecha de vencimiento, Monto, Estado, Fecha de pago.
 */
function handle_payment_quotas_owner(int $userId): void
{
    Auth::requireUser();

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $pageSize = 20;
    $offset = ($page - 1) * $pageSize;

    $where = ['uq.user_id = :user_id'];
    $params = ['user_id' => $userId];
    if (!empty($_GET['initDate'])) {
        $where[] = 'uq.due_date >= :initDate';
        $params['initDate'] = substr((string) $_GET['initDate'], 0, 10);
    }
    if (!empty($_GET['endDate'])) {
        $where[] = 'uq.due_date <= :endDate';
        $params['endDate'] = substr((string) $_GET['endDate'], 0, 10);
    }

    $pdo = Database::connection();

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM user_quotas uq WHERE ' . implode(' AND ', $where));
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $sql = 'SELECT uq.id, uq.status, uq.amount, uq.due_date, uq.pay_date, uq.receipt
            FROM user_quotas uq
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
            'amount' => (float) $r['amount'],
            'dueDate' => Response::isoDate($r['due_date']),
            'payDate' => Response::isoDate($r['pay_date']),
            'receipt' => $r['receipt'],
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

/**
 * GET /payment/owners — "Mi estado de cuenta" del colono autenticado.
 *
 * Hallazgo 2026-08-06: el Consejo reportó "hay acceso a las cuentas pero
 * NO a la información" para colonos con rol `owner`. Se rastreó el
 * componente real `app-owners-resume` (ruta `/home/owners/resume`, a
 * donde el guard de rutas del bundle -- `role=='owner' && url!='owners'`
 * -- REDIRIGE automáticamente a cualquier colono que inicie sesión). Ese
 * componente llama a `paymentService.getAllPaymentsByUser()` → esta ruta,
 * SIN parámetro de id -- el usuario se identifica por el JWT, nunca por
 * la URL, así que un colono jamás puede pedir los pagos de otro. La ruta
 * simplemente no existía → 404 → tabla vacía, aunque el login (200 OK)
 * hiciera parecer que "sí hay acceso".
 *
 * **Forma de respuesta atípica a propósito:** `{items, total}`, NO
 * `{status,items,meta}` -- el componente lee `H.items` y `H.total`
 * directo (confirmado en el `.subscribe()` real), sin envoltura.
 */
function handle_payment_owners(): void
{
    $claims = Auth::requireUser();
    $userId = (int) $claims['sub'];

    $pdo = Database::connection();
    $stmt = $pdo->prepare(
        'SELECT uq.id, uq.status, uq.amount, uq.due_date, uq.pay_date, uq.receipt,
                p.id AS property_id, p.numOficial, s.name AS street_name
         FROM user_quotas uq
         LEFT JOIN property p ON p.id = uq.property_id
         LEFT JOIN street s ON s.id = p.street_id
         WHERE uq.user_id = :user_id
         ORDER BY uq.due_date DESC'
    );
    $stmt->bindValue('user_id', $userId, PDO::PARAM_INT);
    $stmt->execute();

    $items = array_map(static function (array $r): array {
        return [
            'id' => $r['id'],
            'status' => $r['status'],
            'amount' => (float) $r['amount'],
            'dueDate' => Response::isoDate($r['due_date']),
            'payDate' => Response::isoDate($r['pay_date']),
            'receipt' => $r['receipt'],
            'property' => [
                'id' => $r['property_id'],
                'numOficial' => $r['numOficial'],
                'extras' => [],
                'street' => ['name' => $r['street_name']],
            ],
        ];
    }, $stmt->fetchAll());

    Response::json(200, ['items' => $items, 'total' => count($items)]);
}

/**
 * GET /payment/get-file/{id} — descarga del comprobante/recibo de una cuota.
 *
 * **Limitación honesta heredada, no un bug oculto:** `user_quotas.receipt`
 * tiene datos reales para 5,170 registros históricos (ej.
 * `images/2022/11/9/<uuid>.jpeg`), pero esos archivos físicos NUNCA se
 * migraron a este hosting -- vivían en la infraestructura Node.js/Cloud
 * Run original de la V1, sin rastro de su URL base. Sigue respondiendo
 * 404 honesto para esos.
 *
 * **Ampliado 2026-08-10:** `receipt` ahora también puede apuntar a un
 * archivo real subido vía `POST /payment/upload-receipt` (guardado en
 * `assets/uploads/receipts/`). Se distingue por existencia real en disco,
 * no por un flag nuevo en el schema -- si el archivo existe, se sirve; si
 * no (caso histórico de arriba), 404 honesto. Valida que la cuota
 * pertenezca al colono autenticado (si no es admin/super_admin) antes de
 * admitir que el registro existe, para no filtrar si otro colono tiene o
 * no comprobante.
 */
function handle_payment_get_file(int $quotaId): void
{
    $claims = Auth::requireUser();

    $pdo = Database::connection();
    $stmt = $pdo->prepare('SELECT user_id, receipt FROM user_quotas WHERE id = ?');
    $stmt->execute([$quotaId]);
    $row = $stmt->fetch();

    $isOwner = ($claims['role'] ?? '') === 'owner';
    if ($row === false || ($isOwner && (int) $row['user_id'] !== (int) $claims['sub'])) {
        Response::error(404, 'Comprobante no encontrado');
    }

    $receipt = (string) ($row['receipt'] ?? '');
    // basename() descarta cualquier segmento de ruta -- receipt nunca debe
    // poder usarse para hacer path traversal fuera de receipts/, ni
    // siquiera con valores históricos que sí contienen "/".
    $safeName = basename($receipt);
    $fullPath = __DIR__ . '/../../../assets/uploads/receipts/' . $safeName;

    if ($receipt === '' || $safeName === '' || !is_file($fullPath)) {
        Response::error(
            404,
            'El comprobante existe en el registro histórico pero el archivo no está disponible: '
            . 'no se migró desde la infraestructura original de la V1.'
        );
    }

    $mimeTypes = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'];
    $ext = strtolower((string) pathinfo($safeName, PATHINFO_EXTENSION));
    $contentType = $mimeTypes[$ext] ?? 'application/octet-stream';

    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: inline; filename="' . $safeName . '"');
        header('Content-Length: ' . (string) filesize($fullPath));
    }
    readfile($fullPath);
    exit;
}

/**
 * POST /payment/upload-receipt (multipart/form-data: quotaId, file)
 * (admin/super_admin) — Tarea aprobada 2026-08-10: subir el comprobante
 * real de una cuota (transferencia, depósito, etc.) en vez de solo marcar
 * "pagado" a ciegas. Whitelist estricta de extensión + tipo MIME real
 * (nunca se confía en el `Content-Type` que manda el cliente, se valida
 * con `finfo` contra el contenido real del archivo -- Zero Trust), máximo
 * 5 MB, nombre de archivo generado server-side (nunca el nombre original
 * del cliente, evita path traversal y colisiones). Actualiza
 * `user_quotas.receipt` con el nombre generado -- NO cambia `status`
 * (subir un comprobante y marcar "pagado" son acciones separadas, ver
 * `PUT /payment/pay/{id}`, que ya acepta `receipt` como texto si se
 * quiere hacer en un solo paso).
 */
function handle_payment_upload_receipt(): void
{
    $claims = Auth::requireUser();
    Auth::requireRole($claims, ['admin', 'super_admin']);

    $quotaId = (int) ($_POST['quotaId'] ?? 0);
    if ($quotaId <= 0) {
        Response::error(400, 'quotaId es obligatorio');
    }

    $pdo = Database::connection();
    $existing = $pdo->prepare('SELECT id, receipt FROM user_quotas WHERE id = ?');
    $existing->execute([$quotaId]);
    $before = $existing->fetch();
    if ($before === false) {
        Response::error(404, 'Cuota no encontrada');
    }

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $uploadErr = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
        $message = $uploadErr === UPLOAD_ERR_INI_SIZE || $uploadErr === UPLOAD_ERR_FORM_SIZE
            ? 'El archivo excede el tamaño máximo permitido por el servidor.'
            : 'No se recibió ningún archivo válido.';
        Response::error(400, $message);
    }

    $tmpPath = $_FILES['file']['tmp_name'];
    $sizeBytes = (int) $_FILES['file']['size'];
    $maxBytes = 5 * 1024 * 1024;
    if ($sizeBytes > $maxBytes) {
        Response::error(400, 'El archivo supera el máximo de 5 MB.');
    }

    // Extensión declarada por el cliente SOLO se usa como pista inicial;
    // la validación real es el tipo MIME verdadero del contenido (finfo),
    // nunca el Content-Type que manda el navegador (falsificable).
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $realMimeType = finfo_file($finfo, $tmpPath);
    finfo_close($finfo);

    $allowedMimeToExt = [
        'application/pdf' => 'pdf',
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
    ];

    if (!isset($allowedMimeToExt[$realMimeType])) {
        Response::error(400, 'Tipo de archivo no permitido. Solo se aceptan PDF, PNG o JPG.');
    }
    $ext = $allowedMimeToExt[$realMimeType];

    $uploadsDir = __DIR__ . '/../../../assets/uploads/receipts/';
    $generatedName = 'uq' . $quotaId . '_' . bin2hex(random_bytes(16)) . '.' . $ext;
    $destination = $uploadsDir . $generatedName;

    if (!move_uploaded_file($tmpPath, $destination)) {
        Response::error(500, 'No se pudo guardar el archivo en el servidor.');
    }

    $pdo->prepare('UPDATE user_quotas SET receipt = ? WHERE id = ?')->execute([$generatedName, $quotaId]);

    // Si había un comprobante propio anterior (no un path histórico de la
    // V1, que nunca existe en disco aquí) se borra para no acumular
    // archivos huérfanos con cada reemplazo.
    $previousReceipt = (string) ($before['receipt'] ?? '');
    if ($previousReceipt !== '') {
        $previousPath = $uploadsDir . basename($previousReceipt);
        if (is_file($previousPath) && $previousPath !== $destination) {
            @unlink($previousPath);
        }
    }

    Audit::log('user_quotas', $quotaId, 'update', (int) $claims['sub'], [
        'before' => ['receipt' => $before['receipt']],
        'after' => ['receipt' => $generatedName],
    ]);

    Response::json(200, [
        'status' => 'ok',
        'message' => 'Comprobante subido correctamente',
        'receipt' => $generatedName,
    ]);
}

/**
 * GET /quota/filter?page=&name=&order=
 *
 * Hallazgo 2026-08-08: esta es la ruta REAL que llama el botón "Buscar"
 * de la pantalla "Cuotas" (`filterQuotasAndOrder`) — `GET /quota` (bare)
 * nunca tuvo búsqueda, así que la barra de búsqueda de Cuotas jamás
 * funcionó hasta ahora. Confirmado decompilando `main.<hash>.js`.
 */
function handle_quota_filter(): void
{
    Auth::requireUser();

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $pageSize = 20;
    $offset = ($page - 1) * $pageSize;
    $order = strtoupper((string) ($_GET['order'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';

    // sortKey/sortDir (2026-08-10, panel nuevo): whitelist estricta,
    // aditivo sobre el `order` legado (que el bundle Angular viejo sigue
    // usando, siempre sobre `name`) -- si no viene sortKey, el
    // comportamiento previo no cambia.
    $sortColumns = ['name' => 'name', 'cost' => 'cost'];
    $sortKey = $sortColumns[$_GET['sortKey'] ?? ''] ?? 'name';
    $sortDir = isset($_GET['sortKey']) ? ((($_GET['sortDir'] ?? '') === 'desc') ? 'DESC' : 'ASC') : $order;

    $where = ['1=1'];
    $params = [];
    if (!empty($_GET['name'])) {
        $where[] = 'name LIKE :name';
        $params['name'] = '%' . $_GET['name'] . '%';
    }

    $pdo = Database::connection();
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM quota WHERE ' . implode(' AND ', $where));
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT id, name, cost FROM quota WHERE " . implode(' AND ', $where) . "
         ORDER BY {$sortKey} {$sortDir}
         LIMIT :limit OFFSET :offset"
    );
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue('limit', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = array_map(static function (array $r): array {
        $r['cost'] = (float) $r['cost'];
        return $r;
    }, $stmt->fetchAll());

    Response::json(200, [
        'status' => 'ok',
        'items' => $rows,
        'meta' => ['total' => $total, 'totalPages' => (int) ceil($total / $pageSize), 'page' => $page],
    ]);
}

/**
 * POST /quota/create — crear tipo de cuota (admin/super_admin).
 * Payload real del formulario: { name, cost }.
 */
function handle_quota_create(): void
{
    $claims = Auth::requireUser();
    Auth::requireRole($claims, ['admin', 'super_admin']);

    $body = json_decode(file_get_contents('php://input') ?: '', true) ?? [];
    $name = trim((string) ($body['name'] ?? ''));
    $cost = $body['cost'] ?? null;

    if ($name === '') {
        Response::error(400, 'name es obligatorio');
    }
    if ($cost === null || !is_numeric($cost) || (float) $cost < 0) {
        Response::error(400, 'cost es obligatorio y debe ser un número positivo');
    }

    $pdo = Database::connection();
    $stmt = $pdo->prepare('INSERT INTO quota (name, cost) VALUES (?, ?)');
    $stmt->execute([$name, (float) $cost]);
    $id = (int) $pdo->lastInsertId();

    Audit::log('quota', $id, 'create', (int) $claims['sub'], ['after' => ['name' => $name, 'cost' => (float) $cost]]);

    Response::json(201, ['status' => 'ok', 'id' => $id]);
}

/**
 * DELETE /quota/delete/{id} — borrar tipo de cuota (admin/super_admin).
 * `quota` no tiene `deleteAt` (no es una entidad con soft-delete en el
 * schema real) -- borrado físico, pero solo si ninguna `property` lo usa
 * actualmente (evita romper la FK `fk_property_quota` en producción con
 * un error 500 feo; se valida antes y se responde un 409 claro).
 */
function handle_quota_delete(int $id): void
{
    $claims = Auth::requireUser();
    Auth::requireRole($claims, ['admin', 'super_admin']);

    $pdo = Database::connection();
    $existing = $pdo->prepare('SELECT id, name, cost FROM quota WHERE id = ?');
    $existing->execute([$id]);
    $before = $existing->fetch();
    if ($before === false) {
        Response::error(404, 'Cuota no encontrada');
    }

    $inUse = $pdo->prepare('SELECT COUNT(*) FROM property WHERE quota_id = ? AND deleteAt IS NULL');
    $inUse->execute([$id]);
    if ((int) $inUse->fetchColumn() > 0) {
        Response::error(409, 'No se puede eliminar: hay propiedades usando esta cuota');
    }

    $pdo->prepare('DELETE FROM quota WHERE id = ?')->execute([$id]);

    Audit::log('quota', $id, 'delete', (int) $claims['sub'], ['before' => $before]);

    Response::json(200, ['status' => 'ok']);
}

/**
 * PUT /quota/update/{id} — editar un tipo de cuota (admin/super_admin).
 *
 * Ruta corregida 2026-08-08: el botón "Editar" real llama a
 * `PUT /quota/update/{id}`, no `/quota/{id}` — confirmado decompilando
 * `updateQuota()` en `main.<hash>.js`. Campos reales editables: name,
 * cost. **Corrección de alcance:** la tabla `quota` no tiene columna
 * `description` -- solo `id`, `name`, `cost` (ver
 * 02_CODEX_Y_SCHEMA_MAESTRO.md). No se fabricó una columna nueva sin
 * autorización explícita.
 */
function handle_quota_update(int $id): void
{
    $claims = Auth::requireUser();
    Auth::requireRole($claims, ['admin', 'super_admin']);

    $pdo = Database::connection();
    $existing = $pdo->prepare('SELECT id, name, cost FROM quota WHERE id = ?');
    $existing->execute([$id]);
    $before = $existing->fetch();
    if ($before === false) {
        Response::error(404, 'Cuota no encontrada');
    }

    $body = json_decode(file_get_contents('php://input') ?: '', true) ?? [];
    $name = trim((string) ($body['name'] ?? ''));
    $cost = $body['cost'] ?? null;

    if ($name === '') {
        Response::error(400, 'name es obligatorio');
    }
    if ($cost === null || !is_numeric($cost) || (float) $cost < 0) {
        Response::error(400, 'cost es obligatorio y debe ser un número positivo');
    }

    $stmt = $pdo->prepare('UPDATE quota SET name = ?, cost = ? WHERE id = ?');
    $stmt->execute([$name, (float) $cost, $id]);

    Audit::log('quota', $id, 'update', (int) $claims['sub'], [
        'before' => $before,
        'after' => ['name' => $name, 'cost' => (float) $cost],
    ]);

    Response::json(200, ['status' => 'ok', 'id' => $id]);
}

/** GET /quota/{id}/history — bitácora de cambios de un tipo de cuota (admin/super_admin). */
function handle_quota_history(int $id): void
{
    $claims = Auth::requireUser();
    Auth::requireRole($claims, ['admin', 'super_admin']);

    Response::json(200, ['status' => 'ok', 'items' => Audit::history('quota', $id)]);
}

/**
 * POST /quota/generate-period { "period": "YYYY-MM", "dryRun": bool }
 * (admin/super_admin) — Propuesta de valor #1 aprobada 2026-08-10:
 * genera la cuota del período para cada propiedad activa que aún no la
 * tenga. Idempotente: una propiedad ya cubierta para ese período (existe
 * un user_quotas con due_date dentro del mes) se salta, así que correrlo
 * dos veces para el mismo período no duplica nada -- se puede reintentar
 * sin miedo. `dryRun: true` corre exactamente la misma lógica pero sin
 * el INSERT final, para poder ver el resultado antes de comprometerse.
 *
 * Determinación del propietario: usa el `user_id` del `user_quotas` más
 * reciente de esa propiedad (mismo criterio que ya usa
 * panel/propiedades.html para mostrar "Propietario actual" -- `property`
 * no tiene FK directa a colono, la relación es histórica vía
 * `user_quotas`). Una propiedad sin ningún historial de cuotas no se
 * puede facturar sola -- se reporta en `skippedNoOwner` para asignación
 * manual, no se inventa un dueño.
 */
function handle_quota_generate_period(): void
{
    $claims = Auth::requireUser();
    Auth::requireRole($claims, ['admin', 'super_admin']);

    $body = json_decode(file_get_contents('php://input') ?: '', true) ?? [];
    $period = (string) ($body['period'] ?? '');
    $dryRun = !empty($body['dryRun']);

    if (!preg_match('#^\d{4}-(0[1-9]|1[0-2])$#', $period)) {
        Response::error(400, 'period es obligatorio, formato YYYY-MM');
    }

    $pdo = Database::connection();

    $properties = $pdo->query(
        'SELECT id, quota_id, due_day FROM property WHERE deleteAt IS NULL AND quota_id IS NOT NULL'
    )->fetchAll();

    $created = [];
    $skippedExisting = [];
    $skippedNoOwner = [];

    if (!$dryRun) {
        $pdo->beginTransaction();
    }

    try {
        foreach ($properties as $property) {
            $propertyId = (int) $property['id'];

            $existsStmt = $pdo->prepare(
                "SELECT id FROM user_quotas WHERE property_id = ? AND DATE_FORMAT(due_date, '%Y-%m') = ? LIMIT 1"
            );
            $existsStmt->execute([$propertyId, $period]);
            if ($existsStmt->fetchColumn() !== false) {
                $skippedExisting[] = $propertyId;
                continue;
            }

            $ownerStmt = $pdo->prepare(
                'SELECT user_id FROM user_quotas WHERE property_id = ? AND user_id IS NOT NULL ORDER BY due_date DESC LIMIT 1'
            );
            $ownerStmt->execute([$propertyId]);
            $ownerId = $ownerStmt->fetchColumn();
            if ($ownerId === false) {
                $skippedNoOwner[] = $propertyId;
                continue;
            }

            $quotaStmt = $pdo->prepare('SELECT cost FROM quota WHERE id = ?');
            $quotaStmt->execute([(int) $property['quota_id']]);
            $cost = $quotaStmt->fetchColumn();
            if ($cost === false) {
                $skippedNoOwner[] = $propertyId; // quota_id apunta a un tipo de cuota inexistente -- caso raro, mismo cubo de "requiere revisión manual"
                continue;
            }

            // Día de corte clamped al último día real del mes (evita "31 de
            // febrero" -- LAST_DAY + LEAST es la forma segura en MySQL).
            $dueDay = (int) $property['due_day'] ?: 1;
            $dueDateStmt = $pdo->prepare(
                "SELECT LEAST(?, DAY(LAST_DAY(CONCAT(?, '-01')))) AS clamped_day"
            );
            $dueDateStmt->execute([$dueDay, $period]);
            $clampedDay = (int) $dueDateStmt->fetchColumn();
            $dueDate = sprintf('%s-%02d', $period, $clampedDay);

            if (!$dryRun) {
                $insertStmt = $pdo->prepare(
                    'INSERT INTO user_quotas (due_date, status, amount, user_id, property_id) VALUES (?, 1, ?, ?, ?)'
                );
                $insertStmt->execute([$dueDate, (float) $cost, (int) $ownerId, $propertyId]);
                $newId = (int) $pdo->lastInsertId();
                Audit::log('user_quotas', $newId, 'create', (int) $claims['sub'], [
                    'after' => ['due_date' => $dueDate, 'amount' => (float) $cost, 'user_id' => (int) $ownerId, 'property_id' => $propertyId, 'source' => 'generate-period'],
                ]);
            }

            $created[] = ['propertyId' => $propertyId, 'dueDate' => $dueDate, 'amount' => (float) $cost];
        }

        if (!$dryRun) {
            $pdo->commit();
        }
    } catch (\Throwable $e) {
        if (!$dryRun) {
            $pdo->rollBack();
        }
        throw $e;
    }

    Response::json(200, [
        'status' => 'ok',
        'dryRun' => $dryRun,
        'period' => $period,
        'created' => $created,
        'skippedExisting' => $skippedExisting,
        'skippedNoOwner' => $skippedNoOwner,
        'summary' => [
            'createdCount' => count($created),
            'skippedExistingCount' => count($skippedExisting),
            'skippedNoOwnerCount' => count($skippedNoOwner),
        ],
    ]);
}

/**
 * PUT /payment/pay/{id} — marcar una cuota como pagada (admin/super_admin).
 *
 * Ruta REAL del botón "Editar" de la pantalla "Pagos"
 * (`app-list-payments`, chunk 104 — `updatePaymentById({file:...}, id)`).
 * El formulario real ata un `<input type="file">` directo a un
 * `FormGroup` y lo manda con `Content-Type: application/json` -- eso no
 * serializa un archivo binario real (un `File` de un input HTML no se
 * convierte a JSON útil así, es un patrón roto en el propio bundle
 * compilado, no algo que este endpoint pueda arreglar del lado del
 * servidor). Este endpoint hace lo que SÍ puede hacer con la evidencia
 * real: marca la cuota como pagada (status=2, pay_date=NOW()) y guarda
 * `receipt` solo si llega como texto plano (nombre/ruta), nunca finge
 * procesar un archivo que no puede recibir correctamente.
 */
function handle_payment_pay(int $id): void
{
    $claims = Auth::requireUser();
    Auth::requireRole($claims, ['admin', 'super_admin']);

    $pdo = Database::connection();
    $existing = $pdo->prepare('SELECT id, status, amount, receipt FROM user_quotas WHERE id = ?');
    $existing->execute([$id]);
    $before = $existing->fetch();
    if ($before === false) {
        Response::error(404, 'Cuota no encontrada');
    }

    $body = json_decode(file_get_contents('php://input') ?: '', true) ?? [];
    $receipt = $before['receipt'];
    if (isset($body['file']) && is_string($body['file']) && trim($body['file']) !== '') {
        $receipt = trim($body['file']);
    }

    $pdo->prepare('UPDATE user_quotas SET status = 2, pay_date = NOW(), receipt = ? WHERE id = ?')
        ->execute([$receipt, $id]);

    Audit::log('user_quotas', $id, 'update', (int) $claims['sub'], [
        'before' => $before,
        'after' => ['status' => 2, 'receipt' => $receipt],
    ]);

    Response::json(200, ['status' => 'ok', 'id' => $id]);
}
