<?php
declare(strict_types=1);

/** CORS restringido por whitelist explícita — nunca "*", y nunca refleja Origin sin validar. */
final class Cors
{
    public static function apply(): void
    {
        $allowed = array_map('trim', explode(',', Env::get('CORS_ALLOWED_ORIGINS', '')));
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        if ($origin !== '' && in_array($origin, $allowed, true)) {
            header("Access-Control-Allow-Origin: {$origin}");
            header('Vary: Origin');
            header('Access-Control-Allow-Credentials: true');
        }

        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        header('Access-Control-Max-Age: 600');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
}
