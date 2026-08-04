<?php
declare(strict_types=1);

/**
 * Rate limit por IP basado en archivo, sin dependencias externas.
 *
 * Usa una carpeta dentro de la propia app (api/v2/storage/), siempre dentro
 * de open_basedir en cPanel compartido. Si el filesystem no es escribible,
 * el rate limit se salta (fail-open) en vez de tumbar el login/contacto --
 * es una medida de defensa adicional, no el mecanismo de seguridad
 * principal (ese es bcrypt + JWT + validación de payload).
 */
final class RateLimit
{
    public static function check(string $bucket, int $maxAttempts, int $windowSeconds): void
    {
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $dir = dirname(__DIR__) . '/storage/ratelimit';
            if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                return; // no se pudo crear el directorio -> fail-open, no bloquear la request
            }

            $file = $dir . '/' . preg_replace('/[^a-zA-Z0-9_.]/', '_', "{$bucket}_{$ip}") . '.json';

            $now = time();
            $data = ['count' => 0, 'windowStart' => $now];
            if (is_file($file)) {
                $decoded = json_decode((string) @file_get_contents($file), true);
                if (is_array($decoded) && isset($decoded['count'], $decoded['windowStart'])) {
                    $data = $decoded;
                }
            }

            if ($now - (int) $data['windowStart'] > $windowSeconds) {
                $data = ['count' => 0, 'windowStart' => $now];
            }

            $data['count']++;
            @file_put_contents($file, json_encode($data), LOCK_EX);

            if ($data['count'] > $maxAttempts) {
                Response::error(429, 'Demasiados intentos. Intenta de nuevo en unos minutos.');
            }
        } catch (\Throwable $e) {
            error_log('[fidepaz_v2] RateLimit fail-open: ' . $e->getMessage());
            // No relanzar: un fallo del limitador nunca debe tumbar la request.
        }
    }
}
