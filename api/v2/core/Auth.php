<?php
declare(strict_types=1);

/** Extrae y valida el Bearer token de cada request protegido. Zero Trust: nada se asume, todo se verifica. */
final class Auth
{
    /** @return array<string,mixed> claims del token. Corta la ejecución con 401 si falta o es inválido. */
    public static function requireUser(): array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            Response::error(401, 'Token de autenticación no proporcionado');
        }

        $claims = Jwt::verify($m[1], Env::required('JWT_SECRET'));
        if ($claims === null) {
            Response::error(401, 'Token inválido o expirado');
        }

        return $claims;
    }

    /** Corta con 403 si el rol del token no está en la lista permitida. */
    public static function requireRole(array $claims, array $allowedRoles): void
    {
        if (!in_array($claims['role'] ?? null, $allowedRoles, true)) {
            Response::error(403, 'No tienes permisos para esta operación');
        }
    }
}
