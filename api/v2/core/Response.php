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

    /**
     * Convierte "YYYY-MM-DD HH:MM:SS" (formato nativo MySQL DATETIME) a
     * "YYYY-MM-DDTHH:MM:SS" (ISO-8601 con "T" literal).
     *
     * El bundle Angular hace `t.split("T")` sobre las fechas de vencimiento
     * antes de construir un objeto Date (ver `formatDueDate` en
     * administrator/104.<hash>.js) -- sin la "T", `split` no encuentra
     * separador, `r[0]` queda con la fecha completa, y el `new Date(...)`
     * resultante es inválido -> `NaN/NaN/NaN` en pantalla (visto en consola
     * real, pantalla Pagos, 2026-08-05).
     */
    public static function isoDate(?string $mysqlDatetime): ?string
    {
        if ($mysqlDatetime === null || $mysqlDatetime === '' || $mysqlDatetime === '0000-00-00 00:00:00') {
            return null;
        }

        return str_replace(' ', 'T', $mysqlDatetime);
    }
}
