<?php
declare(strict_types=1);

/**
 * Módulo "Mensajes" (2026-08-12) -- mensajería interna colono <-> administración.
 * Tablas: `messages` (hilo) + `message_replies` (cada mensaje dentro del hilo).
 * Estados: nuevo (colono escribió, admin no ha respondido) | abierto (admin lo
 * abrió, en proceso) | respondido (admin ya contestó) | cerrado (admin lo cerró
 * a mano). Transición automática nuevo->abierto al ver el detalle como
 * admin/super_admin; toda respuesta del colono regresa el hilo a "nuevo";
 * toda respuesta de admin/super_admin lo deja en "respondido". No restringido
 * a super_admin (a diferencia de Pagos) -- tanto admin como super_admin
 * gestionan la bandeja completa.
 */

/**
 * Crea un hilo de notificación automática dirigido a un colono (2026-08-14,
 * Objetivo 5 -- Caja). NO es un handler HTTP: lo llama directamente
 * handle_caja_register_payment() (api/v2/routes/caja.php) justo después de
 * confirmar la transacción de pago. $authorId es el super_admin que
 * registró el cobro -- mismo criterio que un mensaje individual dirigido:
 * el remitente real es quien lo disparó, no el propio colono destinatario.
 * El hilo nace directo en "respondido" -- es un aviso, no algo que
 * requiera que el colono reciba "nuevo" pidiendo atención de un admin.
 */
function messages_notify_payment(PDO $pdo, int $userId, int $authorId, string $subject, string $body): void
{
    $pdo->prepare("INSERT INTO messages (user_id, subject, status) VALUES (?, ?, 'respondido')")
        ->execute([$userId, $subject]);
    $messageId = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO message_replies (message_id, author_id, body) VALUES (?, ?, ?)')
        ->execute([$messageId, $authorId, $body]);
}

/**
 * GET /messages/search-recipient?q= -- autocompletado para elegir a QUIÉN
 * dirigir un mensaje individual (2026-08-14). Busca por nombre, código de
 * propiedad ("lote") o correo. A propósito NO reusa handle_caja_search()
 * (mismo tipo de búsqueda, pero esa está restringida a super_admin porque
 * vive en el módulo financiero) -- aquí cualquier admin/super_admin puede
 * usarla, Mensajes no es exclusivo de super_admin.
 */
function handle_messages_search_recipient(): void
{
    $claims = Auth::requireUser();
    Auth::requireRole($claims, ['admin', 'super_admin']);

    $q = trim((string) ($_GET['q'] ?? ''));
    if (mb_strlen($q) < 2) {
        Response::json(200, ['status' => 'ok', 'items' => []]);
    }

    $pdo = Database::connection();
    $like = '%' . $q . '%';
    $stmt = $pdo->prepare(
        "SELECT id, name, email, code
         FROM `user`
         WHERE role = 'owner' AND deleteAt IS NULL
           AND (name LIKE :q1 OR email LIKE :q2 OR code LIKE :q3)
         ORDER BY name
         LIMIT 20"
    );
    $stmt->execute(['q1' => $like, 'q2' => $like, 'q3' => $like]);

    Response::json(200, ['status' => 'ok', 'items' => $stmt->fetchAll()]);
}

/** GET /messages?status=&q=&page= -- bandeja. Colono ve solo lo suyo, admin/super_admin ve todo. */
function handle_messages_list(): void
{
    $claims = Auth::requireUser();
    $isAdmin = in_array($claims['role'] ?? '', ['admin', 'super_admin'], true);

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $pageSize = 20;
    $offset = ($page - 1) * $pageSize;

    $where = ['1=1'];
    $params = [];

    if (!$isAdmin) {
        $where[] = 'm.user_id = :self_id';
        $params['self_id'] = (int) $claims['sub'];
    }

    if (!empty($_GET['status']) && in_array($_GET['status'], ['nuevo', 'abierto', 'respondido', 'cerrado'], true)) {
        $where[] = 'm.status = :status';
        $params['status'] = $_GET['status'];
    }

    if ($isAdmin && !empty($_GET['q'])) {
        $where[] = '(m.subject LIKE :q OR u.name LIKE :q OR u.email LIKE :q)';
        $params['q'] = '%' . $_GET['q'] . '%';
    }

    $pdo = Database::connection();

    $countStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM messages m JOIN `user` u ON u.id = m.user_id WHERE ' . implode(' AND ', $where)
    );
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT m.id, m.subject, m.status, m.is_broadcast, m.created_at, m.updated_at,
                u.id AS user_id, u.name AS user_name, u.email AS user_email,
                (SELECT body FROM message_replies WHERE message_id = m.id ORDER BY created_at DESC LIMIT 1) AS last_body,
                (SELECT COUNT(*) FROM message_replies WHERE message_id = m.id) AS reply_count
         FROM messages m
         JOIN `user` u ON u.id = m.user_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY m.updated_at DESC
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
            'subject' => $r['subject'],
            'status' => $r['status'],
            'isBroadcast' => (bool) $r['is_broadcast'],
            'user' => ['id' => $r['user_id'], 'name' => $r['user_name'], 'email' => $r['user_email']],
            'lastExcerpt' => $r['last_body'] !== null ? mb_substr((string) $r['last_body'], 0, 140) : null,
            'replyCount' => (int) $r['reply_count'],
            'createdAt' => Response::isoDate($r['created_at']),
            'updatedAt' => Response::isoDate($r['updated_at']),
        ];
    }, $stmt->fetchAll());

    Response::json(200, [
        'status' => 'ok',
        'items' => $items,
        'meta' => ['total' => $total, 'totalPages' => (int) ceil($total / $pageSize), 'page' => $page],
    ]);
}

/** GET /messages/{id} -- detalle del hilo con todas sus respuestas. */
function handle_messages_detail(int $id): void
{
    $claims = Auth::requireUser();
    $isAdmin = in_array($claims['role'] ?? '', ['admin', 'super_admin'], true);

    $pdo = Database::connection();
    $stmt = $pdo->prepare(
        'SELECT m.*, u.name AS user_name, u.email AS user_email
         FROM messages m JOIN `user` u ON u.id = m.user_id WHERE m.id = ?'
    );
    $stmt->execute([$id]);
    $thread = $stmt->fetch();

    if ($thread === false || (!$isAdmin && (int) $thread['user_id'] !== (int) $claims['sub'])) {
        Response::error(404, 'Mensaje no encontrado');
    }

    // Ver el detalle marca "nuevo" -> "abierto" para admin/super_admin -- para
    // el colono viendo su propio hilo no significa "en proceso", no aplica.
    if ($isAdmin && $thread['status'] === 'nuevo') {
        $pdo->prepare("UPDATE messages SET status = 'abierto' WHERE id = ?")->execute([$id]);
        $thread['status'] = 'abierto';
    }

    $repliesStmt = $pdo->prepare(
        'SELECT r.id, r.body, r.created_at, r.author_id, u.name AS author_name, u.role AS author_role
         FROM message_replies r JOIN `user` u ON u.id = r.author_id
         WHERE r.message_id = ? ORDER BY r.created_at ASC'
    );
    $repliesStmt->execute([$id]);

    Response::json(200, [
        'status' => 'ok',
        'thread' => [
            'id' => $thread['id'],
            'subject' => $thread['subject'],
            'status' => $thread['status'],
            'isBroadcast' => (bool) $thread['is_broadcast'],
            'user' => ['id' => $thread['user_id'], 'name' => $thread['user_name'], 'email' => $thread['user_email']],
            'createdAt' => Response::isoDate($thread['created_at']),
        ],
        'replies' => array_map(static function (array $r): array {
            return [
                'id' => $r['id'],
                'body' => $r['body'],
                'authorId' => $r['author_id'],
                'authorName' => $r['author_name'],
                'authorRole' => $r['author_role'],
                'createdAt' => Response::isoDate($r['created_at']),
            ];
        }, $repliesStmt->fetchAll()),
    ]);
}

/** POST /messages { subject, body } -- cualquier usuario autenticado inicia un hilo propio. */
function handle_messages_create(): void
{
    $claims = Auth::requireUser();
    $authorId = (int) $claims['sub'];
    $isAdmin = in_array($claims['role'] ?? '', ['admin', 'super_admin'], true);

    $body = json_decode(file_get_contents('php://input') ?: '', true) ?? [];
    $subject = trim((string) ($body['subject'] ?? ''));
    $messageBody = trim((string) ($body['body'] ?? ''));

    if ($subject === '' || $messageBody === '') {
        Response::error(400, 'subject y body son obligatorios');
    }

    $pdo = Database::connection();

    // Mensaje individual dirigido (2026-08-14): admin/super_admin puede
    // iniciar un hilo A NOMBRE de un colono específico (targetUserId) --
    // el colono jamás puede mandar targetUserId, siempre es dueño de su
    // propio hilo (evita que un owner escriba "en nombre de" otro colono).
    $threadUserId = $authorId;
    $status = 'nuevo';
    if ($isAdmin && !empty($body['targetUserId'])) {
        $targetUserId = (int) $body['targetUserId'];
        $userCheck = $pdo->prepare("SELECT id FROM `user` WHERE id = ? AND role = 'owner' AND deleteAt IS NULL");
        $userCheck->execute([$targetUserId]);
        if ($userCheck->fetch() === false) {
            Response::error(400, 'targetUserId no corresponde a un colono real');
        }
        $threadUserId = $targetUserId;
        // Igual que un masivo: el admin ya "contestó" al iniciar contacto,
        // no aplica el significado de "nuevo" (colono escribió, falta
        // atención del admin).
        $status = 'respondido';
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO messages (user_id, subject, status) VALUES (?, ?, ?)')
            ->execute([$threadUserId, $subject, $status]);
        $messageId = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO message_replies (message_id, author_id, body) VALUES (?, ?, ?)')
            ->execute([$messageId, $authorId, $messageBody]);

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    Response::json(201, ['status' => 'ok', 'id' => $messageId]);
}

/** POST /messages/{id}/reply { body } -- responder un hilo existente. */
function handle_messages_reply(int $id): void
{
    $claims = Auth::requireUser();
    $isAdmin = in_array($claims['role'] ?? '', ['admin', 'super_admin'], true);
    $userId = (int) $claims['sub'];

    $pdo = Database::connection();
    $stmt = $pdo->prepare('SELECT id, user_id, status FROM messages WHERE id = ?');
    $stmt->execute([$id]);
    $thread = $stmt->fetch();

    if ($thread === false || (!$isAdmin && (int) $thread['user_id'] !== $userId)) {
        Response::error(404, 'Mensaje no encontrado');
    }
    if ($thread['status'] === 'cerrado' && !$isAdmin) {
        Response::error(400, 'Este mensaje está cerrado. Inicia uno nuevo si necesitas algo más.');
    }

    $body = json_decode(file_get_contents('php://input') ?: '', true) ?? [];
    $replyBody = trim((string) ($body['body'] ?? ''));
    if ($replyBody === '') {
        Response::error(400, 'body es obligatorio');
    }

    $newStatus = $isAdmin ? 'respondido' : 'nuevo';

    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO message_replies (message_id, author_id, body) VALUES (?, ?, ?)')
            ->execute([$id, $userId, $replyBody]);
        $pdo->prepare('UPDATE messages SET status = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$newStatus, $id]);
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    Response::json(201, ['status' => 'ok']);
}

/** PUT /messages/{id}/status { status } -- admin/super_admin marca abierto/cerrado a mano. */
function handle_messages_update_status(int $id): void
{
    $claims = Auth::requireUser();
    Auth::requireRole($claims, ['admin', 'super_admin']);

    $body = json_decode(file_get_contents('php://input') ?: '', true) ?? [];
    $status = (string) ($body['status'] ?? '');
    if (!in_array($status, ['nuevo', 'abierto', 'respondido', 'cerrado'], true)) {
        Response::error(400, 'status inválido');
    }

    $pdo = Database::connection();
    $stmt = $pdo->prepare('UPDATE messages SET status = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$status, $id]);

    if ($stmt->rowCount() === 0) {
        Response::error(404, 'Mensaje no encontrado');
    }

    Response::json(200, ['status' => 'ok']);
}

/**
 * POST /messages/broadcast { subject, body } -- admin/super_admin. Crea UN
 * hilo por cada colono activo (role=owner, deleteAt IS NULL), todos
 * is_broadcast=1 y ya en estado 'respondido' (es un aviso, no algo que
 * necesite acción del admin) -- mismo modelo que un mensaje 1:1, así que si
 * el colono contesta, se vuelve una conversación normal dentro de su propio
 * hilo sin lógica especial.
 */
function handle_messages_broadcast(): void
{
    $claims = Auth::requireUser();
    Auth::requireRole($claims, ['admin', 'super_admin']);
    $adminId = (int) $claims['sub'];

    $body = json_decode(file_get_contents('php://input') ?: '', true) ?? [];
    $subject = trim((string) ($body['subject'] ?? ''));
    $messageBody = trim((string) ($body['body'] ?? ''));

    if ($subject === '' || $messageBody === '') {
        Response::error(400, 'subject y body son obligatorios');
    }

    $pdo = Database::connection();
    $owners = $pdo->query("SELECT id FROM `user` WHERE role = 'owner' AND deleteAt IS NULL")->fetchAll(PDO::FETCH_COLUMN);

    $pdo->beginTransaction();
    try {
        $insertMsg = $pdo->prepare("INSERT INTO messages (user_id, subject, status, is_broadcast) VALUES (?, ?, 'respondido', 1)");
        $insertReply = $pdo->prepare('INSERT INTO message_replies (message_id, author_id, body) VALUES (?, ?, ?)');
        foreach ($owners as $ownerId) {
            $insertMsg->execute([(int) $ownerId, $subject]);
            $insertReply->execute([(int) $pdo->lastInsertId(), $adminId, $messageBody]);
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    Response::json(201, ['status' => 'ok', 'recipients' => count($owners)]);
}
