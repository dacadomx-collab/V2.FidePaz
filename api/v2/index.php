<?php
declare(strict_types=1);

/**
 * Front controller único de la API de contingencia en PHP. Vive en
 * /api/v2/ para responder exactamente al mismo contrato que el backend
 * Go (backend/main.go, prefijo /api/v2) mientras el binario Go no pueda
 * exponerse públicamente en este hosting compartido (ver
 * reportes/INFORME_EJECUTIVO_V1_VS_V2.md, hallazgo de infraestructura
 * 2026-08-04: sin mod_proxy autogestionable y Node.js Selector
 * restringido en el plan de hosting).
 *
 * REGLA DE ORO: los manejadores de error/excepción/shutdown se registran
 * ANTES que cualquier require o lógica de negocio. Así, cualquier fallo
 * posterior (falta de .env, PDO caído, excepción de negocio) sale SIEMPRE
 * como JSON, nunca como página en blanco.
 *
 * MODO DIAGNÓSTICO: si APP_DEBUG=true en .env, el JSON de error incluye
 * message/file/line reales de la excepción. Por defecto se oculta el
 * detalle interno al cliente.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0'); // nunca mostrar errores crudos al cliente
ini_set('log_errors', '1');

function fidepaz_debug_enabled(): bool
{
    $v = getenv('APP_DEBUG');
    return $v !== false && in_array(strtolower($v), ['1', 'true', 'on', 'yes'], true);
}

function fidepaz_json_fail(int $status, string $message, ?Throwable $e = null): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }
    $payload = ['status' => 'error', 'message' => $message];
    if ($e !== null && fidepaz_debug_enabled()) {
        $payload['message'] = $e->getMessage();
        $payload['file'] = $e->getFile();
        $payload['line'] = $e->getLine();
        $payload['exception'] = get_class($e);
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
}

set_exception_handler(function (Throwable $e): void {
    error_log('[fidepaz_v2] Unhandled exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    fidepaz_json_fail(500, 'Error interno del servidor', $e);
    exit;
});

set_error_handler(function (int $severity, string $message, string $file = '', int $line = 0): bool {
    error_log("[fidepaz_v2] PHP warning/notice: {$message} in {$file}:{$line}");
    return true;
});

register_shutdown_function(function (): void {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('[fidepaz_v2] Fatal: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            $payload = ['status' => 'error', 'message' => 'Error interno del servidor'];
            if (fidepaz_debug_enabled()) {
                $payload['message'] = $error['message'];
                $payload['file'] = $error['file'];
                $payload['line'] = $error['line'];
            }
            echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        }
    }
});

try {
    require __DIR__ . '/core/Env.php';
    Env::load(__DIR__ . '/.env');
    require __DIR__ . '/core/Response.php';
    require __DIR__ . '/core/Database.php';
    require __DIR__ . '/core/Jwt.php';
    require __DIR__ . '/core/Auth.php';
    require __DIR__ . '/core/Cors.php';
    require __DIR__ . '/core/RateLimit.php';
    require __DIR__ . '/routes/auth.php';
    require __DIR__ . '/routes/properties.php';
    require __DIR__ . '/routes/quotas.php';
    require __DIR__ . '/routes/users.php';
    require __DIR__ . '/routes/contact.php';
} catch (\Throwable $e) {
    error_log('[fidepaz_v2] Fallo cargando módulos internos: ' . $e->getMessage());
    fidepaz_json_fail(500, 'Error interno del servidor (carga de módulos)', $e);
    exit;
}

try {
    Cors::apply();

    $method = $_SERVER['REQUEST_METHOD'];
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if ($path === null || $path === false) {
        $path = '/';
    }
    // Este front controller vive físicamente en /api/v2/, y el frontend
    // Angular llama a rutas bajo ese mismo prefijo público (apiUrl =
    // https://v2.fidepaz.org/api/v2) — se despoja aquí para que las rutas
    // internas (auth.php, properties.php, etc.) usen paths simples.
    $path = '/' . trim((string) preg_replace('#^/api/v2#', '', $path), '/');

    if (($method === 'GET' || $method === 'HEAD') && $path === '/') {
        // Health check — mismo contrato que GET /api/v2 y /api/v2/ del backend Go.
        Response::json(200, [
            'status'      => 'ok',
            'system'      => 'FidePaz Core API v2.0 (PHP fallback)',
            'environment' => Env::get('APP_ENV', 'staging'),
        ]);
    } elseif ($method === 'POST' && $path === '/auth/login') {
        handle_auth_login();
    } elseif ($method === 'POST' && $path === '/contact') {
        handle_contact();
    } elseif ($method === 'GET' && $path === '/properties') {
        handle_properties_list();
    } elseif ($method === 'GET' && $path === '/property/filter') {
        // Ruta REAL que usa el panel Angular compilado para "Propiedades".
        handle_properties_filter();
    } elseif ($method === 'GET' && $path === '/property/streets') {
        handle_property_streets();
    } elseif ($method === 'GET' && $path === '/user-quotas') {
        handle_quotas_list();
    } elseif ($method === 'GET' && $path === '/quota') {
        // Ruta REAL que usa el panel Angular compilado para "Cuotas".
        handle_quota_catalog_list();
    } elseif ($method === 'GET' && $path === '/payment/list-owners') {
        // Ruta REAL que usa la pantalla "Pagos".
        handle_payment_list_owners();
    } elseif ($method === 'GET' && $path === '/users') {
        handle_users_list();
    } elseif ($method === 'GET' && $path === '/user/filter') {
        // Ruta REAL que usa el panel Angular compilado para "Propietarios".
        handle_users_filter();
    } elseif ($method === 'GET' && $path === '/catalog/streets') {
        handle_catalog('streets');
    } elseif ($method === 'GET' && $path === '/catalog/quotas') {
        handle_catalog('quotas');
    } else {
        Response::error(404, "Ruta no encontrada: {$method} {$path}");
    }
} catch (\Throwable $e) {
    error_log('[fidepaz_v2] Unhandled en routing/handler: ' . $e->getMessage());
    fidepaz_json_fail(500, 'Error interno del servidor', $e);
}
