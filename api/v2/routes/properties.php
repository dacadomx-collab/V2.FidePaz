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
        // 2 placeholders distintos, no ":name" repetido (mismo bug real
        // encontrado y corregido en /user/filter, 2026-08-08 -- PDO con
        // prepares reales no expande un nombre de parámetro repetido a
        // cada aparición).
        $where[] = '(p.numOficial LIKE :name1 OR s.name LIKE :name2)';
        $term = '%' . $_GET['name'] . '%';
        $params['name1'] = $term;
        $params['name2'] = $term;
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
    // Búsqueda multicampo (2026-08-07): por propietario y por estatus de
    // pago -- `property` no tiene FK directo a un colono, así que se busca
    // vía `user_quotas` (relación real, histórica).
    if (!empty($_GET['owner'])) {
        $where[] = 'EXISTS (SELECT 1 FROM user_quotas uq2 JOIN `user` u2 ON u2.id = uq2.user_id
                             WHERE uq2.property_id = p.id AND u2.name LIKE :owner)';
        $params['owner'] = '%' . $_GET['owner'] . '%';
    }
    if (!empty($_GET['quota_id'])) {
        $where[] = 'p.quota_id = :quota_id';
        $params['quota_id'] = (int) $_GET['quota_id'];
    }
    if (!empty($_GET['status']) && in_array($_GET['status'], ['pending', 'paid'], true)) {
        $statusCode = $_GET['status'] === 'paid' ? 2 : 1;
        $where[] = 'EXISTS (SELECT 1 FROM user_quotas uq3 WHERE uq3.property_id = p.id AND uq3.status = :status_code)';
        $params['status_code'] = $statusCode;
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
                'dueDate' => Response::isoDate($uq['due_date']),
                'payDate' => Response::isoDate($uq['pay_date']),
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

/**
 * PUT /property/{id} — editar una propiedad (admin/super_admin).
 *
 * Ruta y payload corregidos 2026-08-08: el formulario real
 * (`app-edit-property.accept()`) manda `{numOficial,streetId,quotaId,
 * ownerId,day}` (camelCase) a `PUT /property/update/{id}` -- NO
 * `{numOficial,street_id,quota_id,due_day}` a `/property/{id}` como se
 * había asumido sin verificar contra el bundle real.
 *
 * **`ownerId` en el payload:** el formulario SÍ manda un `ownerId`, pero
 * `property` no tiene esa columna (confirmado también contra el schema
 * original de la V1, `mercagee_colonoscore.sql` — nunca existió ahí
 * tampoco). La relación real de dueño es histórica vía `user_quotas`, y
 * reasignar el dueño de una propiedad existente (que ya tiene cuotas
 * cobradas a nombre de alguien) es una decisión de negocio con
 * implicaciones que no se pueden inferir solas -- este endpoint acepta el
 * campo (no lo rechaza) pero NO reasigna nada automáticamente; se
 * documenta en la respuesta. Sí se usa en `/property/create` (ver abajo),
 * donde SÍ tiene sentido: una propiedad nueva necesita su primera cuota
 * asignada a alguien.
 */
function handle_property_update(int $id): void
{
    $claims = Auth::requireUser();
    Auth::requireRole($claims, ['admin', 'super_admin']);

    $pdo = Database::connection();
    $existing = $pdo->prepare('SELECT id, numOficial, due_day, street_id, quota_id FROM property WHERE id = ? AND deleteAt IS NULL');
    $existing->execute([$id]);
    $before = $existing->fetch();
    if ($before === false) {
        Response::error(404, 'Propiedad no encontrada');
    }

    // array_key_exists (no "??"): un PUT parcial que simplemente OMITE
    // streetId/quotaId debe conservar el valor actual, no borrarlo (bug
    // real encontrado y corregido 2026-08-07).
    $body = json_decode(file_get_contents('php://input') ?: '', true) ?? [];
    $numOficial = $body['numOficial'] ?? null;
    $streetId = array_key_exists('streetId', $body) ? $body['streetId'] : $before['street_id'];
    $quotaId = array_key_exists('quotaId', $body) ? $body['quotaId'] : $before['quota_id'];
    $dueDay = (int) ($body['day'] ?? $before['due_day']);

    if ($numOficial === null || !is_numeric($numOficial)) {
        Response::error(400, 'numOficial es obligatorio y debe ser numérico');
    }
    if ($dueDay < 1 || $dueDay > 31) {
        Response::error(400, 'day debe estar entre 1 y 31');
    }

    $stmt = $pdo->prepare(
        'UPDATE property SET numOficial = ?, street_id = ?, quota_id = ?, due_day = ? WHERE id = ?'
    );
    $stmt->execute([(int) $numOficial, $streetId !== null ? (int) $streetId : null, $quotaId !== null ? (int) $quotaId : null, $dueDay, $id]);

    Audit::log('property', $id, 'update', (int) $claims['sub'], ['before' => $before, 'after' => [
        'numOficial' => (int) $numOficial, 'street_id' => $streetId, 'quota_id' => $quotaId, 'due_day' => $dueDay,
    ]]);

    Response::json(200, ['status' => 'ok', 'id' => $id]);
}

/**
 * POST /property/create — crear propiedad nueva (admin/super_admin).
 * Payload real: {numOficial,streetId,quotaId,ownerId,day}. `ownerId` aquí
 * SÍ se usa: crea la primera fila de `user_quotas` (status=1 pendiente,
 * vencimiento hoy, monto = costo de la cuota asignada) para establecer la
 * relación real propiedad↔colono desde el día uno.
 */
function handle_property_create(): void
{
    $claims = Auth::requireUser();
    Auth::requireRole($claims, ['admin', 'super_admin']);

    $body = json_decode(file_get_contents('php://input') ?: '', true) ?? [];
    $numOficial = $body['numOficial'] ?? null;
    $streetId = $body['streetId'] ?? null;
    $quotaId = $body['quotaId'] ?? null;
    $ownerId = $body['ownerId'] ?? null;
    $dueDay = (int) ($body['day'] ?? 1);

    if ($numOficial === null || !is_numeric($numOficial)) {
        Response::error(400, 'numOficial es obligatorio y debe ser numérico');
    }
    if ($dueDay < 1 || $dueDay > 31) {
        Response::error(400, 'day debe estar entre 1 y 31');
    }

    $pdo = Database::connection();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO property (numOficial, street_id, quota_id, due_day) VALUES (?, ?, ?, ?)');
        $stmt->execute([(int) $numOficial, $streetId !== null ? (int) $streetId : null, $quotaId !== null ? (int) $quotaId : null, $dueDay]);
        $id = (int) $pdo->lastInsertId();

        if ($ownerId !== null && $quotaId !== null) {
            $quotaStmt = $pdo->prepare('SELECT cost FROM quota WHERE id = ?');
            $quotaStmt->execute([(int) $quotaId]);
            $cost = $quotaStmt->fetchColumn();
            if ($cost !== false) {
                $pdo->prepare(
                    'INSERT INTO user_quotas (due_date, status, amount, user_id, property_id) VALUES (CURDATE(), 1, ?, ?, ?)'
                )->execute([(float) $cost, (int) $ownerId, $id]);
            }
        }

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    Audit::log('property', $id, 'create', (int) $claims['sub'], [
        'after' => ['numOficial' => (int) $numOficial, 'street_id' => $streetId, 'quota_id' => $quotaId, 'owner_id' => $ownerId, 'due_day' => $dueDay],
    ]);

    Response::json(201, ['status' => 'ok', 'id' => $id]);
}

/**
 * DELETE /property/delete/{id} — baja de una propiedad (admin/super_admin).
 * Soft-delete (`deleteAt = NOW()`), consistente con el resto de la API —
 * un borrado físico rompería la integridad histórica de `user_quotas`
 * (pagos ya registrados a esa propiedad).
 */
function handle_property_delete(int $id): void
{
    $claims = Auth::requireUser();
    Auth::requireRole($claims, ['admin', 'super_admin']);

    $pdo = Database::connection();
    $existing = $pdo->prepare('SELECT id, numOficial FROM property WHERE id = ? AND deleteAt IS NULL');
    $existing->execute([$id]);
    $before = $existing->fetch();
    if ($before === false) {
        Response::error(404, 'Propiedad no encontrada');
    }

    $pdo->prepare('UPDATE property SET deleteAt = NOW() WHERE id = ?')->execute([$id]);

    Audit::log('property', $id, 'delete', (int) $claims['sub'], ['before' => $before]);

    Response::json(200, ['status' => 'ok']);
}

/** GET /property/{id}/history — bitácora de cambios de una propiedad (admin/super_admin). */
function handle_property_history(int $id): void
{
    $claims = Auth::requireUser();
    Auth::requireRole($claims, ['admin', 'super_admin']);

    Response::json(200, ['status' => 'ok', 'items' => Audit::history('property', $id)]);
}
