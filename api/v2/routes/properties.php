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

/**
 * GET /property/filter?page=&name=&streets[]=
 *
 * Este es el endpoint REAL que usa el panel Angular compilado para la
 * pantalla "Propiedades" (confirmado decompilando propertyControllerFilter
 * en main.<hash>.js -- llama a `${basePath}/property/filter`, NUNCA a
 * `/properties`). La forma de cada item también es distinta: el template
 * (600.<hash>.js) lee `r.street.name`, `r.quota.cost` y `r.userquotas`
 * como OBJETOS/ARRAY anidados, no como columnas planas `street_name` /
 * `quota_cost`. Sin este anidado la tabla se queda vacía aunque el JSON
 * tenga datos (acceso a `.name` de `undefined` revienta el *ngFor de Angular
 * en silencio, sin error visible en la UI).
 */
function handle_properties_filter(): void
{
    Auth::requireUser();

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $pageSize = 20;
    $offset = ($page - 1) * $pageSize;

    $where = ['p.deleteAt IS NULL'];
    $params = [];

    if (!empty($_GET['name'])) {
        $where[] = '(p.numOficial LIKE :name OR s.name LIKE :name)';
        $params['name'] = '%' . $_GET['name'] . '%';
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
        'SELECT COUNT(*) FROM property p LEFT JOIN street s ON s.id = p.street_id WHERE ' . implode(' AND ', $where)
    );
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $sql = 'SELECT p.id, p.numOficial, p.due_day,
                   s.id AS street_id, s.name AS street_name,
                   q.id AS quota_id, q.name AS quota_name, q.cost AS quota_cost
            FROM property p
            LEFT JOIN street s ON s.id = p.street_id
            LEFT JOIN quota  q ON q.id = p.quota_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY s.name, p.numOficial
            LIMIT :limit OFFSET :offset';
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue('limit', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $properties = $stmt->fetchAll();

    // userquotas anidadas: solo para las propiedades de esta página (máx.
    // 20), evita el costo de traer pagos de las 194 propiedades a la vez.
    $propertyIds = array_column($properties, 'id');
    $userQuotasByProperty = [];
    if (!empty($propertyIds)) {
        $inPlaceholders = implode(',', array_fill(0, count($propertyIds), '?'));
        $uqStmt = $pdo->prepare(
            "SELECT uq.id, uq.status, uq.amount, uq.due_date, uq.pay_date, uq.property_id,
                    u.id AS user_id, u.name AS user_name, u.email AS user_email
             FROM user_quotas uq
             LEFT JOIN `user` u ON u.id = uq.user_id
             WHERE uq.property_id IN ({$inPlaceholders})
             ORDER BY uq.due_date DESC"
        );
        $uqStmt->execute($propertyIds);
        foreach ($uqStmt->fetchAll() as $uq) {
            $userQuotasByProperty[(int) $uq['property_id']][] = [
                'id' => $uq['id'],
                'status' => $uq['status'],
                // (float): PDO/mysqlnd siempre devuelve DECIMAL como string;
                // sin este cast, sumar client-side (`p+=Z.amount`) hace
                // concatenación de strings en JS y revienta el pipe de
                // moneda con NG02100.
                'amount' => (float) $uq['amount'],
                'dueDate' => $uq['due_date'],
                'payDate' => $uq['pay_date'],
                'user' => [
                    'id' => $uq['user_id'],
                    'name' => $uq['user_name'],
                    'email' => $uq['user_email'],
                ],
            ];
        }
    }

    $items = array_map(static function (array $p) use ($userQuotasByProperty): array {
        return [
            'id' => $p['id'],
            'numOficial' => $p['numOficial'],
            'dueDay' => $p['due_day'],
            'street' => ['id' => $p['street_id'], 'name' => $p['street_name']],
            'quota' => ['id' => $p['quota_id'], 'name' => $p['quota_name'], 'cost' => $p['quota_cost'] !== null ? (float) $p['quota_cost'] : null],
            'userquotas' => $userQuotasByProperty[(int) $p['id']] ?? [],
            'extras' => [],
        ];
    }, $properties);

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
 * GET /property/streets?search=
 *
 * Autocompletado de calles (`propertyService.getStreetNamesByTerm`,
 * usado por el componente `ng-select` en los formularios de
 * Propiedades/Pagos). **Forma de respuesta atípica a propósito:** array
 * plano en la raíz, NO `{status,items,meta}` -- el bundle hace
 * `this.searchResults = r` directo sobre la respuesta (confirmado
 * decompilando el `.subscribe` real), y el componente `ng-select` llama
 * `.map()` sobre eso mismo -- envolverlo en un objeto revienta con
 * `TypeError: n.map is not a function` (visto en consola real 2026-08-05).
 */
function handle_property_streets(): void
{
    Auth::requireUser();

    $pdo = Database::connection();
    if (!empty($_GET['search'])) {
        $stmt = $pdo->prepare('SELECT id, name FROM street WHERE name LIKE :search ORDER BY name');
        $stmt->bindValue('search', '%' . $_GET['search'] . '%');
        $stmt->execute();
    } else {
        $stmt = $pdo->query('SELECT id, name FROM street ORDER BY name');
    }
    $rows = $stmt->fetchAll();

    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($rows, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * GET /property/extras/all — catálogo de "costos extra" por propiedad.
 *
 * La tabla `extras` está fuera de alcance de v2.0 (decisión ya documentada
 * en db/schema.sql: "Se excluye la tabla extras"). Se responde vacío en
 * lugar de 404 porque el bundle compilado hace
 * `for(...;i<=meta.totalPages;...)` sobre la respuesta sin comprobar si
 * existe -- un 404 (sin `meta`) revienta esa pantalla con un TypeError.
 */
function handle_extras_all(): void
{
    Auth::requireUser();

    Response::json(200, [
        'status' => 'ok',
        'items' => [],
        'meta' => ['total' => 0, 'totalPages' => 0, 'page' => 1],
    ]);
}
