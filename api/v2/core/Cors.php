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
        // "Content-Disposition" agregado 2026-08-05: los botones "Descargar"
        // (Reportes y Estado del propietario) mandan ese header en la
        // petición saliente (visto en el bundle: `.set("Content-Disposition",
        // "attachment")` sobre el request, no la respuesta). Sin listarlo
        // aquí, el preflight lo rechaza y el navegador bloquea la petición
        // ANTES de enviarla -- sin error visible, porque el `.subscribe()`
        // del bundle no tiene callback de error. Confirmado simulando el
        // preflight real (Access-Control-Request-Headers con
        // content-disposition) contra este endpoint.
        header('Access-Control-Allow-Headers: Authorization, Content-Type, Content-Disposition');
        // Expone el header al JS del cliente (no lo usa hoy -- el bundle
        // arma el nombre del archivo del lado del cliente -- pero lo pide
        // explícitamente esta directiva y no cuesta nada dejarlo listo).
        header('Access-Control-Expose-Headers: Content-Disposition');
        header('Access-Control-Max-Age: 600');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
}
