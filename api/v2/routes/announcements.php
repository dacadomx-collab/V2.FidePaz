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
        'SELECT id, title, content, excerpt, image_url, published_at
         FROM announcements
         WHERE category = :category
         ORDER BY published_at DESC
         LIMIT :limit OFFSET :offset'
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
            '_embedded' => $r['image_url']
                ? ['wp:featuredmedia' => [['source_url' => $r['image_url']]]]
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
 * POST /announcements — crear un comunicado oficial (admin/super_admin).
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
    $imageUrl = trim((string) ($body['image_url'] ?? '')) ?: null;

    if ($title === '' || $content === '') {
        Response::error(400, 'title y content son obligatorios');
    }
    if (!in_array($category, ['comunicados', 'financiero', 'reportes'], true)) {
        Response::error(400, 'category inválida');
    }

    $pdo = Database::connection();
    $stmt = $pdo->prepare(
        'INSERT INTO announcements (title, content, excerpt, category, image_url, author_id)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$title, $content, $excerpt, $category, $imageUrl, (int) $claims['sub']]);

    Response::json(201, ['status' => 'ok', 'id' => (int) $pdo->lastInsertId()]);
}
