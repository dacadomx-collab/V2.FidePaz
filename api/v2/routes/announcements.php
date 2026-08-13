<?php
declare(strict_types=1);

/**
 * Hallazgo "Código Rojo" (2026-08-14): /comunicados e /informes devolvían
 * 500 en producción/staging porque `visibility` (autorizada 2026-08-11,
 * aplicada en local, con el ALTER TABLE pendiente entregado varias veces
 * para remoto) nunca se llegó a correr ahí. Todo endpoint que la
 * mencionaba en el SQL tronaba con "Unknown column" en cuanto alguien
 * cargaba Comunicados o Informes Financieros. En vez de seguir bloqueado
 * esperando ese ALTER, esta función centraliza la verificación (con caché
 * estático -- una sola consulta SHOW COLUMNS por request, sin importar
 * cuántas funciones la llamen) y CADA handler de este archivo que toca
 * `visibility` ahora la usa para degradar con gracia: si la columna no
 * existe todavía, simplemente no se filtra/escribe por ella -- ni el 500,
 * ni un dato inventado, el comportamiento cae al de antes de que
 * `visibility` existiera (todo lo publicado se trata como visible).
 */
function announcements_has_visibility_column(PDO $pdo): bool
{
    static $cached = null;
    if ($cached === null) {
        $cached = $pdo->query("SHOW COLUMNS FROM announcements LIKE 'visibility'")->rowCount() > 0;
    }
    return $cached;
}

/**
 * GET /posts?_embed&categories=39&orderby=date&order=desc&per_page=20&page=
 *
 * Reemplazo real de la llamada hardcodeada del bundle Angular a
 * `https://fidepaz.org/wp-json/wp/v2/posts?...` (WordPress dado de baja,
 * causaba ERR_FAILED/CORS en la pantalla "Comunicados"). El bundle solo se
 * parchó en la URL (ver administrator/*.js, cadena original conservada
 * como comentario junto al parche) -- la lógica de consumo
 * (`this.posts = t`, `.title.rendered`, `.content.rendered`,
 * `._embedded['wp:featuredmedia']`) sigue intacta, así que esta ruta
 * replica esa forma exacta en vez del contrato `{status,items,meta}` del
 * resto de la API. `categories` mapea a la columna `category`:
 * 39=comunicados, 41=financiero, 42=reportes (mismos IDs que WordPress
 * usaba, documentado en 02_CODEX_Y_SCHEMA_MAESTRO.md).
 *
 * Sin auth a propósito: la llamada original a wp-json tampoco llevaba
 * token (dominio externo, el interceptor de Angular no le adjuntaba
 * Authorization), y el contenido en sí es de interés público. Igual que
 * V1, es de solo lectura.
 */
function handle_posts_list(): void
{
    $categoryMap = [39 => 'comunicados', 41 => 'financiero', 42 => 'reportes'];
    $categoryId = (int) ($_GET['categories'] ?? 39);
    $category = $categoryMap[$categoryId] ?? 'comunicados';

    $perPage = max(1, min(50, (int) ($_GET['per_page'] ?? 20)));
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $offset = ($page - 1) * $perPage;

    $pdo = Database::connection();
    $visibilityWhere = announcements_has_visibility_column($pdo) ? " AND visibility = 'public'" : '';
    $stmt = $pdo->prepare(
        "SELECT id, title, content, excerpt, image_url, published_at
         FROM announcements
         WHERE category = :category AND status = 'published'{$visibilityWhere}
         ORDER BY published_at DESC
         LIMIT :limit OFFSET :offset"
    );
    $stmt->bindValue('category', $category);
    $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    // Forma WP REST: array plano en la raíz (no {status,items,meta}) --
    // el bundle hace `this.posts = t` directo sobre la respuesta.
    $posts = array_map(static function (array $r): array {
        return [
            'id' => $r['id'],
            'date' => str_replace(' ', 'T', $r['published_at']),
            'title' => ['rendered' => $r['title']],
            'content' => ['rendered' => $r['content']],
            'excerpt' => ['rendered' => $r['excerpt'] ?? ''],
            // Estructura exacta que lee getImagePost() en el bundle:
            // _embedded['wp:featuredmedia'][0].media_details.sizes.medium.source_url
            // -- un nivel más profundo de lo que esta ruta mandaba antes
            // (bug real encontrado 2026-08-07 decompilando la plantilla de
            // tarjetas: toda imagen caía siempre al placeholder genérico
            // dummyimage.com por la forma incorrecta, sin error visible).
            '_embedded' => $r['image_url']
                ? ['wp:featuredmedia' => [['media_details' => ['sizes' => ['medium' => ['source_url' => $r['image_url']]]]]]]
                : ['wp:featuredmedia' => []],
        ];
    }, $rows);

    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($posts, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * GET /comunicados?page= y GET /informes?page=
 *
 * Endpoints V2 nativos pedidos explícitamente por la Directiva Táctica de
 * "reconstrucción nativa de Comunicados e Informes" -- a diferencia de
 * `/posts` (que existe solo porque el bundle YA compilado tiene la URL de
 * wp-json parchada hacia ese path exacto y no vale la pena volver a
 * parchear el bundle), estos usan el contrato estándar de la API
 * (`{status,items,meta}`) porque no hay ningún consumidor legacy que fuerce
 * la forma WP-REST. Filtran por `category` = 'comunicados' / 'reportes'
 * respectivamente sobre la misma tabla `announcements`.
 */
function handle_comunicados_list(): void
{
    handle_announcements_by_category('comunicados');
}

/**
 * GET /avisos?category=&year= — feed para el colono AUTENTICADO dentro del
 * panel (panel/mi-cuenta.html), pedido 2026-08-11. Distinto de /comunicados
 * y /informes (públicos, sin login, solo `visibility='public'`): aquí
 * cualquier usuario ya autenticado (cualquier rol -- no requiere
 * admin/super_admin) ve TODO lo `status='published'` sin filtrar por
 * `visibility`, porque estar dentro del panel YA implica ser un colono
 * legítimo -- "private" solo significa "no exponer en la Landing Page
 * pública sin login", no "ocultar de los propios colonos". `category`
 * opcional (comunicados|financiero|reportes); `year` opcional, filtra por
 * año de `published_at` -- pensado para el filtro por año que pidió el
 * Arquitecto en el perfil del colono.
 */
function handle_avisos_feed(): void
{
    Auth::requireUser();

    $pdo = Database::connection();
    $where = ["status = 'published'"];
    $params = [];

    if (!empty($_GET['category']) && in_array($_GET['category'], ['comunicados', 'financiero', 'reportes'], true)) {
        $where[] = 'category = :category';
        $params['category'] = $_GET['category'];
    }
    if (!empty($_GET['year']) && ctype_digit((string) $_GET['year'])) {
        $where[] = 'YEAR(published_at) = :year';
        $params['year'] = (int) $_GET['year'];
    }

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $pageSize = 20;
    $offset = ($page - 1) * $pageSize;

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM announcements WHERE ' . implode(' AND ', $where));
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    // "visibility" es opcional aquí a propósito (2026-08-12): a diferencia de
    // /comunicados, /informes, /posts y el CRUD admin -- donde esa columna
    // es indispensable para filtrar qué es público -- este feed NO filtra
    // por visibility (ver docblock arriba), solo la MUESTRA como badge
    // informativo en el panel/avisos.html.
    $visibilityCol = announcements_has_visibility_column($pdo) ? ', visibility' : '';

    $stmt = $pdo->prepare(
        "SELECT id, title, content, excerpt, category, image_url, archivo_pdf_url, published_at{$visibilityCol}
         FROM announcements
         WHERE " . implode(' AND ', $where) . '
         ORDER BY published_at DESC
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
            'title' => $r['title'],
            'content' => $r['content'],
            'excerpt' => $r['excerpt'],
            'category' => $r['category'],
            'visibility' => $r['visibility'] ?? null,
            'imageUrl' => $r['image_url'],
            'archivoUrl' => $r['archivo_pdf_url'],
            'publishedAt' => Response::isoDate($r['published_at']),
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
 * GET /avisos/years — años distintos con al menos un aviso publicado, para
 * poblar el <select> de filtro en el panel sin hardcodear un rango de años.
 */
function handle_avisos_years(): void
{
    Auth::requireUser();

    $pdo = Database::connection();
    $years = $pdo->query(
        "SELECT DISTINCT YEAR(published_at) AS y FROM announcements WHERE status = 'published' ORDER BY y DESC"
    )->fetchAll(PDO::FETCH_COLUMN);

    Response::json(200, ['status' => 'ok', 'years' => array_map('intval', $years)]);
}

function handle_informes_list(): void
{
    handle_announcements_by_category('reportes');
}

function handle_announcements_by_category(string $category): void
{
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $pageSize = 20;
    $offset = ($page - 1) * $pageSize;

    $pdo = Database::connection();
    $visibilityWhere = announcements_has_visibility_column($pdo) ? " AND visibility = 'public'" : '';

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM announcements WHERE category = ? AND status = 'published'{$visibilityWhere}");
    $countStmt->execute([$category]);
    $total = (int) $countStmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT id, title, content, excerpt, image_url, archivo_pdf_url, published_at
         FROM announcements
         WHERE category = :category AND status = 'published'{$visibilityWhere}
         ORDER BY published_at DESC
         LIMIT :limit OFFSET :offset"
    );
    $stmt->bindValue('category', $category);
    $stmt->bindValue('limit', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $items = array_map(static function (array $r): array {
        return [
            'id' => $r['id'],
            'title' => $r['title'],
            'content' => $r['content'],
            'excerpt' => $r['excerpt'],
            'imageUrl' => $r['image_url'],
            'archivoUrl' => $r['archivo_pdf_url'],
            'publishedAt' => Response::isoDate($r['published_at']),
        ];
    }, $rows);

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
 * GET /announcements?page=&q=&category=&status= — listado ADMIN (2026-08-10, panel nuevo).
 * A diferencia de `handle_announcements_by_category`, ve TODAS las categorías y estados
 * (incluye `draft`, no solo `published`) -- necesario para que `panel/comunicados.html` pueda
 * gestionar contenido antes de publicarlo. No forma parte del contrato que el bundle V1 leía de
 * WordPress; es la vía real de administración ahora que existe una tabla propia.
 */
function handle_announcements_admin_list(): void
{
    $claims = Auth::requireUser();
    Auth::requireRole($claims, ['admin', 'super_admin']);

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $pageSize = 20;
    $offset = ($page - 1) * $pageSize;

    $where = ['1=1'];
    $params = [];
    if (!empty($_GET['q'])) {
        $where[] = 'title LIKE :q';
        $params['q'] = '%' . $_GET['q'] . '%';
    }
    if (!empty($_GET['category']) && in_array($_GET['category'], ['comunicados', 'financiero', 'reportes'], true)) {
        $where[] = 'category = :category';
        $params['category'] = $_GET['category'];
    }
    if (!empty($_GET['status']) && in_array($_GET['status'], ['published', 'draft'], true)) {
        $where[] = 'status = :status';
        $params['status'] = $_GET['status'];
    }

    $sortColumns = ['title' => 'title', 'category' => 'category', 'status' => 'status', 'published_at' => 'published_at'];
    $sortKey = $sortColumns[$_GET['sortKey'] ?? ''] ?? 'published_at';
    $sortDir = (($_GET['sortDir'] ?? '') === 'asc') ? 'ASC' : 'DESC';

    $pdo = Database::connection();

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM announcements WHERE ' . implode(' AND ', $where));
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    // content SÍ va en el SELECT (2026-08-10): no existe un GET
    // /announcements/{id} aparte -- panel/comunicados.html prellena el
    // formulario de edición con la fila ya cargada en la tabla, igual que
    // Propietarios/Propiedades. Omitirlo aquí habría mandado el textarea
    // vacío en cada edición y borrado el contenido real al guardar.
    $visibilityCol = announcements_has_visibility_column($pdo) ? ', visibility' : '';
    $stmt = $pdo->prepare(
        'SELECT id, title, content, excerpt, category, status' . $visibilityCol . ', image_url, archivo_pdf_url, published_at
         FROM announcements
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY ' . $sortKey . ' ' . $sortDir . '
         LIMIT :limit OFFSET :offset'
    );
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue('limit', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    Response::json(200, [
        'status' => 'ok',
        'items' => $stmt->fetchAll(),
        'meta' => [
            'total' => $total,
            'totalPages' => (int) ceil($total / $pageSize),
            'page' => $page,
        ],
    ]);
}

/**
 * POST /announcements — crear un comunicado/informe (admin/super_admin).
 * No forma parte del contrato que el bundle V1 esperaba (eso solo leía de
 * WordPress); es la vía real para que el panel administre contenido nuevo
 * ahora que existe una tabla propia.
 */
function handle_announcements_create(): void
{
    $claims = Auth::requireUser();
    Auth::requireRole($claims, ['admin', 'super_admin']);

    $body = json_decode(file_get_contents('php://input') ?: '', true) ?? [];
    $title = trim((string) ($body['title'] ?? ''));
    $content = trim((string) ($body['content'] ?? ''));
    $excerpt = trim((string) ($body['excerpt'] ?? '')) ?: null;
    $category = (string) ($body['category'] ?? 'comunicados');
    $status = (string) ($body['status'] ?? 'published');
    $visibility = (string) ($body['visibility'] ?? 'private');
    $imageUrl = trim((string) ($body['image_url'] ?? '')) ?: null;
    $archivoPdfUrl = trim((string) ($body['archivo_pdf_url'] ?? '')) ?: null;
    $publishedAtRaw = trim((string) ($body['published_at'] ?? ''));

    if ($title === '' || $content === '') {
        Response::error(400, 'title y content son obligatorios');
    }
    if (!in_array($category, ['comunicados', 'financiero', 'reportes'], true)) {
        Response::error(400, 'category inválida');
    }
    if (!in_array($status, ['published', 'draft'], true)) {
        Response::error(400, 'status inválido (published|draft)');
    }
    if (!in_array($visibility, ['public', 'private'], true)) {
        Response::error(400, 'visibility inválida (public|private)');
    }

    // published_at es opcional -- por defecto la columna toma CURRENT_TIMESTAMP.
    // Se permite fijarlo a mano (ej. "2024-08-01") para Informes Financieros,
    // donde la fecha real es el periodo que reporta el PDF, no el momento en
    // que el admin lo sube al panel.
    $publishedAt = null;
    if ($publishedAtRaw !== '') {
        $ts = strtotime($publishedAtRaw);
        if ($ts === false) {
            Response::error(400, 'published_at inválido');
        }
        $publishedAt = date('Y-m-d H:i:s', $ts);
    }

    $pdo = Database::connection();
    $columns = ['title', 'content', 'excerpt', 'category', 'status', 'image_url', 'archivo_pdf_url', 'author_id'];
    $values = [$title, $content, $excerpt, $category, $status, $imageUrl, $archivoPdfUrl, (int) $claims['sub']];
    // Si la columna no existe todavía (ALTER TABLE pendiente en este
    // entorno), simplemente no se intenta escribir -- nunca truena el
    // INSERT completo por un campo que el remitente no controla.
    if (announcements_has_visibility_column($pdo)) {
        $columns[] = 'visibility';
        $values[] = $visibility;
    }
    if ($publishedAt !== null) {
        $columns[] = 'published_at';
        $values[] = $publishedAt;
    }
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $stmt = $pdo->prepare(
        'INSERT INTO announcements (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')'
    );
    $stmt->execute($values);

    Response::json(201, ['status' => 'ok', 'id' => (int) $pdo->lastInsertId()]);
}

/** PUT /announcements/{id} — editar un comunicado/informe (admin/super_admin). */
function handle_announcements_update(int $id): void
{
    $claims = Auth::requireUser();
    Auth::requireRole($claims, ['admin', 'super_admin']);

    $pdo = Database::connection();
    $existing = $pdo->prepare('SELECT id FROM announcements WHERE id = ?');
    $existing->execute([$id]);
    if ($existing->fetch() === false) {
        Response::error(404, 'Comunicado no encontrado');
    }

    $body = json_decode(file_get_contents('php://input') ?: '', true) ?? [];
    $title = trim((string) ($body['title'] ?? ''));
    $content = trim((string) ($body['content'] ?? ''));
    $excerpt = trim((string) ($body['excerpt'] ?? '')) ?: null;
    $category = (string) ($body['category'] ?? 'comunicados');
    $status = (string) ($body['status'] ?? 'published');
    $visibility = (string) ($body['visibility'] ?? 'private');
    $imageUrl = trim((string) ($body['image_url'] ?? '')) ?: null;
    $archivoPdfUrl = trim((string) ($body['archivo_pdf_url'] ?? '')) ?: null;
    $publishedAtRaw = trim((string) ($body['published_at'] ?? ''));

    if ($title === '' || $content === '') {
        Response::error(400, 'title y content son obligatorios');
    }
    if (!in_array($category, ['comunicados', 'financiero', 'reportes'], true)) {
        Response::error(400, 'category inválida');
    }
    if (!in_array($status, ['published', 'draft'], true)) {
        Response::error(400, 'status inválido (published|draft)');
    }
    if (!in_array($visibility, ['public', 'private'], true)) {
        Response::error(400, 'visibility inválida (public|private)');
    }

    $setClauses = ['title = ?', 'content = ?', 'excerpt = ?', 'category = ?', 'status = ?', 'image_url = ?', 'archivo_pdf_url = ?'];
    $values = [$title, $content, $excerpt, $category, $status, $imageUrl, $archivoPdfUrl];
    if (announcements_has_visibility_column($pdo)) {
        $setClauses[] = 'visibility = ?';
        $values[] = $visibility;
    }
    if ($publishedAtRaw !== '') {
        $ts = strtotime($publishedAtRaw);
        if ($ts === false) {
            Response::error(400, 'published_at inválido');
        }
        $setClauses[] = 'published_at = ?';
        $values[] = date('Y-m-d H:i:s', $ts);
    }
    $values[] = $id;

    $stmt = $pdo->prepare('UPDATE announcements SET ' . implode(', ', $setClauses) . ' WHERE id = ?');
    $stmt->execute($values);

    Response::json(200, ['status' => 'ok', 'id' => $id]);
}

/** DELETE /announcements/{id} — borrar un comunicado/informe (admin/super_admin). */
function handle_announcements_delete(int $id): void
{
    $claims = Auth::requireUser();
    Auth::requireRole($claims, ['admin', 'super_admin']);

    $pdo = Database::connection();
    $stmt = $pdo->prepare('DELETE FROM announcements WHERE id = ?');
    $stmt->execute([$id]);

    if ($stmt->rowCount() === 0) {
        Response::error(404, 'Comunicado no encontrado');
    }

    Response::json(200, ['status' => 'ok']);
}

/**
 * POST /announcements/upload — sube el PDF adjunto de un comunicado/informe
 * (admin/super_admin). Mismo patrón de validación que
 * handle_payment_upload_receipt (quotas.php): el MIME real del archivo
 * (finfo, nunca el Content-Type declarado por el navegador) decide si se
 * acepta, no la extensión. Solo PDF aquí -- a diferencia de los comprobantes
 * de pago, este adjunto es el documento en sí (informe financiero /
 * comunicado), no una imagen de recibo.
 *
 * Devuelve solo la URL relativa -- no toca la tabla `announcements`
 * directamente porque el formulario del panel (comunicados.html /
 * informes.html) sube el archivo primero y luego manda esa URL como parte
 * del payload normal de POST/PUT /announcements, junto con el resto de los
 * campos (título, contenido, categoría, etc.) en un solo guardado.
 */
function handle_announcements_upload_pdf(): void
{
    $claims = Auth::requireUser();
    Auth::requireRole($claims, ['admin', 'super_admin']);

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $uploadErr = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
        $message = $uploadErr === UPLOAD_ERR_INI_SIZE || $uploadErr === UPLOAD_ERR_FORM_SIZE
            ? 'El archivo excede el tamaño máximo permitido por el servidor.'
            : 'No se recibió ningún archivo válido.';
        Response::error(400, $message);
    }

    $tmpPath = $_FILES['file']['tmp_name'];
    $sizeBytes = (int) $_FILES['file']['size'];
    $maxBytes = 10 * 1024 * 1024; // informes financieros escaneados pesan más que un comprobante suelto
    if ($sizeBytes > $maxBytes) {
        Response::error(400, 'El archivo supera el máximo de 10 MB.');
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $realMimeType = finfo_file($finfo, $tmpPath);
    finfo_close($finfo);

    if ($realMimeType !== 'application/pdf') {
        Response::error(400, 'Tipo de archivo no permitido. Solo se aceptan PDF.');
    }

    $uploadsDir = __DIR__ . '/../../../assets/uploads/comunicados/';
    $generatedName = 'doc_' . bin2hex(random_bytes(16)) . '.pdf';
    $destination = $uploadsDir . $generatedName;

    if (!move_uploaded_file($tmpPath, $destination)) {
        Response::error(500, 'No se pudo guardar el archivo en el servidor.');
    }

    Response::json(200, [
        'status' => 'ok',
        'url' => 'assets/uploads/comunicados/' . $generatedName,
    ]);
}
