<?php
declare(strict_types=1);

/** Extrae y valida el Bearer token de cada request protegido. Zero Trust: nada se asume, todo se verifica. */
final class Auth
{
    /** @return array<string,mixed> claims del token. Corta la ejecución con 401 si falta o es inválido. */
    public static function requireUser(): array
    {
        $header = self::authorizationHeader();
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            Response::error(401, 'Token de autenticación no proporcionado');
        }

        $claims = Jwt::verify($m[1], Env::required('JWT_SECRET'));
        if ($claims === null) {
            Response::error(401, 'Token inválido o expirado');
        }

        return $claims;
    }

    /**
     * Apache en este hosting (XAMPP local y, según se confirme, también el hosting cPanel real)
     * NO llena $_SERVER['HTTP_AUTHORIZATION'] por defecto -- requiere `CGIPassAuth On`, que no está
     * activo aquí. Confirmado 2026-08-11 con un script de diagnóstico: HTTP_AUTHORIZATION y
     * REDIRECT_HTTP_AUTHORIZATION llegaban null aunque el navegador SÍ mandaba el header (visible en
     * DevTools) -- todo endpoint protegido devolvía 401 "Token no proporcionado" con un JWT
     * perfectamente válido, efecto real: cualquier usuario podía iniciar sesión pero la app lo
     * regresaba al login en la siguiente pantalla. apache_request_headers()/getallheaders() sí ven
     * el header real -- se usan como fallback, en ese orden, antes de rendirse.
     */
    private static function authorizationHeader(): string
    {
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            return $_SERVER['HTTP_AUTHORIZATION'];
        }
        if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        $headers = [];
        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers() ?: [];
        } elseif (function_exists('getallheaders')) {
            $headers = getallheaders() ?: [];
        }
        foreach ($headers as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                return $value;
            }
        }

        return '';
    }

    /** Corta con 403 si el rol del token no está en la lista permitida. */
    public static function requireRole(array $claims, array $allowedRoles): void
    {
        if (!in_array($claims['role'] ?? null, $allowedRoles, true)) {
            Response::error(403, 'No tienes permisos para esta operación');
        }
    }
}
