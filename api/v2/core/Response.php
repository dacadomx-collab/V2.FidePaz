<?php
declare(strict_types=1);

final class Response
{
    // Nota de compatibilidad: el tipo de retorno "never" (PHP 8.1+) se quitó
    // deliberadamente. Si el PHP de cPanel es 7.4/8.0, declarar "never" es
    // un ParseError que revienta la carga de TODO el script con un 500 en
    // blanco. Sin tipo de retorno esta función sigue funcionando igual
    // (siempre termina en exit) en cualquier versión de PHP >= 7.0.
    public static function json(int $status, array $payload)
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function error(int $status, string $message)
    {
        self::json($status, ['status' => 'error', 'message' => $message]);
    }
}
