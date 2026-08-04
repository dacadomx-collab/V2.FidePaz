<?php
declare(strict_types=1);

/**
 * POST /contact  { "name": "...", "email": "...", "message": "..." }
 * Público (sin JWT, igual que /auth/login), protegido por rate limit.
 * Espejo del handler Go equivalente (backend/handlers/contact.go) para
 * mantener el mismo contrato mientras el binario Go no esté expuesto.
 */
function fidepaz_sanitize_contact_field(string $raw): string
{
    $stripped = preg_replace('/<[^>]*>/', '', $raw);
    return htmlspecialchars(trim((string) $stripped), ENT_QUOTES, 'UTF-8');
}

function handle_contact(): void
{
    RateLimit::check('contact', 5, 600); // máx 5 mensajes / 10 min / IP (mismo límite que el backend Go)

    $body = json_decode(file_get_contents('php://input'), true);
    $name = fidepaz_sanitize_contact_field((string) ($body['name'] ?? ''));
    $email = fidepaz_sanitize_contact_field((string) ($body['email'] ?? ''));
    $message = fidepaz_sanitize_contact_field((string) ($body['message'] ?? ''));

    if ($name === '' || $email === '' || $message === '') {
        Response::error(400, 'Nombre, correo y mensaje son requeridos');
    }
    if (strlen($name) > 100 || strlen($email) > 150 || strlen($message) > 1000) {
        Response::error(400, 'Uno o más campos exceden la longitud permitida');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        Response::error(400, 'Correo electrónico inválido');
    }

    $pdo = Database::connection();
    $stmt = $pdo->prepare(
        'INSERT INTO contact_messages (name, email, message, ip_address) VALUES (:name, :email, :message, :ip)'
    );
    $stmt->execute([
        'name'    => $name,
        'email'   => $email,
        'message' => $message,
        'ip'      => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);

    Response::json(200, ['status' => 'ok', 'message' => 'Mensaje recibido']);
}
