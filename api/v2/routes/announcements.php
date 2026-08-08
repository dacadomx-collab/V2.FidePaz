<?php
declare(strict_types=1);

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
    $stmt = $pdo->prepare(
        "SELECT id, title, content, excerpt, image_url, published_at
         FROM announcements
         WHERE category = :category AND status = 'published'
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

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM announcements WHERE category = ? AND status = 'published'");
    $countStmt->execute([$category]);
    $total = (int) $countStmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT id, title, content, excerpt, image_url, archivo_pdf_url, published_at
         FROM announcements
         WHERE category = :category AND status = 'published'
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
    $imageUrl = trim((string) ($body['image_url'] ?? '')) ?: null;
    $archivoPdfUrl = trim((string) ($body['archivo_pdf_url'] ?? '')) ?: null;

    if ($title === '' || $content === '') {
        Response::error(400, 'title y content son obligatorios');
    }
    if (!in_array($category, ['comunicados', 'financiero', 'reportes'], true)) {
        Response::error(400, 'category inválida');
    }
    if (!in_array($status, ['published', 'draft'], true)) {
        Response::error(400, 'status inválido (published|draft)');
    }

    $pdo = Database::connection();
    $stmt = $pdo->prepare(
        'INSERT INTO announcements (title, content, excerpt, category, status, image_url, archivo_pdf_url, author_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$title, $content, $excerpt, $category, $status, $imageUrl, $archivoPdfUrl, (int) $claims['sub']]);

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
    $imageUrl = trim((string) ($body['image_url'] ?? '')) ?: null;
    $archivoPdfUrl = trim((string) ($body['archivo_pdf_url'] ?? '')) ?: null;

    if ($title === '' || $content === '') {
        Response::error(400, 'title y content son obligatorios');
    }
    if (!in_array($category, ['comunicados', 'financiero', 'reportes'], true)) {
        Response::error(400, 'category inválida');
    }
    if (!in_array($status, ['published', 'draft'], true)) {
        Response::error(400, 'status inválido (published|draft)');
    }

    $stmt = $pdo->prepare(
        'UPDATE announcements
         SET title = ?, content = ?, excerpt = ?, category = ?, status = ?, image_url = ?, archivo_pdf_url = ?
         WHERE id = ?'
    );
    $stmt->execute([$title, $content, $excerpt, $category, $status, $imageUrl, $archivoPdfUrl, $id]);

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
