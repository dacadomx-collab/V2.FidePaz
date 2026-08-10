<?php
declare(strict_types=1);

/**
 * POST /admin/test-email { "to": "..." } — Propuesta de valor #2, endpoint
 * de diagnóstico (2026-08-10). Restringido a `super_admin` (no
 * admin/super_admin como el resto de operaciones administrativas) porque
 * expone en su respuesta el detalle crudo del handshake SMTP -- incluye
 * las respuestas del servidor de correo, que pueden filtrar información
 * de infraestructura si SMTP está mal configurado (ej. mensajes de error
 * con rutas internas del proveedor). Nunca expone `SMTP_PASS` en la
 * respuesta, solo el resultado del handshake.
 *
 * Uso previsto: una vez que el Capitán configure SMTP_HOST/SMTP_USER/
 * SMTP_PASS reales en api/v2/.env, llamar esta ruta con un correo de
 * prueba propio para confirmar que el handshake de red completo (TCP →
 * TLS → AUTH → envío) funciona antes de activar
 * /auth/forgot-password en producción.
 */
function handle_admin_test_email(): void
{
    $claims = Auth::requireUser();
    Auth::requireRole($claims, ['super_admin']);

    $body = json_decode(file_get_contents('php://input') ?: '', true) ?? [];
    $to = trim((string) ($body['to'] ?? ''));

    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        Response::error(400, 'to es obligatorio y debe ser un email válido');
    }

    $result = Mailer::sendWithDiagnostics(
        $to,
        'Prueba de correo — FidePaz',
        '<p>Este es un correo de prueba del endpoint de diagnóstico SMTP de FidePaz V2.0.</p>'
        . '<p>Si lo recibiste, el handshake de red (' . htmlspecialchars($to, ENT_QUOTES, 'UTF-8') . ') funciona correctamente.</p>'
    );

    Response::json($result['success'] ? 200 : 502, [
        'status' => $result['success'] ? 'ok' : 'error',
        'message' => $result['success'] ? 'Correo de prueba enviado.' : 'El envío de prueba falló -- revisa "steps" para ver en qué paso.',
        'transport' => $result['transport'],
        'steps' => $result['steps'],
    ]);
}

/**
 * GET /audit-logs?page=&entityType=&from=&to=&changedBy= (admin/super_admin)
 * — 2026-08-10, panel visual de auditoría. `Audit::history($tipo,$id)` ya
 * existía desde el 2026-08-07 para ver el historial de UNA fila específica
 * (consumido por `GET /{entidad}/{id}/history`); esta ruta es distinta:
 * un navegador global de `audit_logs` con filtros, para el botón "📜 Ver
 * Historial" de la barra superior de Propietarios/Propiedades, que
 * muestra actividad reciente de todo el tipo de entidad, no de una fila.
 */
function handle_audit_logs_list(): void
{
    $claims = Auth::requireUser();
    Auth::requireRole($claims, ['admin', 'super_admin']);

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $pageSize = 20;
    $offset = ($page - 1) * $pageSize;

    $where = ['1=1'];
    $params = [];

    if (!empty($_GET['entityType']) && in_array($_GET['entityType'], ['user', 'property', 'quota', 'user_quotas', 'announcements'], true)) {
        $where[] = 'al.entity_type = :entityType';
        $params['entityType'] = $_GET['entityType'];
    }
    if (!empty($_GET['from'])) {
        $where[] = 'al.created_at >= :from';
        $params['from'] = substr((string) $_GET['from'], 0, 10) . ' 00:00:00';
    }
    if (!empty($_GET['to'])) {
        $where[] = 'al.created_at <= :to';
        $params['to'] = substr((string) $_GET['to'], 0, 10) . ' 23:59:59';
    }
    if (!empty($_GET['changedBy'])) {
        $where[] = 'al.changed_by = :changedBy';
        $params['changedBy'] = (int) $_GET['changedBy'];
    }

    $pdo = Database::connection();

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM audit_logs al WHERE ' . implode(' AND ', $where));
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT al.id, al.entity_type, al.entity_id, al.action, al.details_json, al.created_at,
                u.name AS changed_by_name
         FROM audit_logs al
         LEFT JOIN `user` u ON u.id = al.changed_by
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY al.created_at DESC
         LIMIT :limit OFFSET :offset'
    );
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue('limit', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $items = array_map(static function (array $r): array {
        return [
            'id' => $r['id'],
            'entityType' => $r['entity_type'],
            'entityId' => $r['entity_id'],
            'action' => $r['action'],
            'details' => json_decode($r['details_json'] ?? '{}', true),
            'changedByName' => $r['changed_by_name'],
            'createdAt' => Response::isoDate($r['created_at']),
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
