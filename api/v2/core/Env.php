<?php
declare(strict_types=1);

/**
 * Carga .env sin dependencias externas (formato simple KEY=VALUE).
 * Compatibilidad: usa strpos()/substr() en vez de str_starts_with()/
 * str_contains() (PHP 8.0+) para funcionar también en PHP 7.4.
 */
final class Env
{
    private static $loaded = false;

    /**
     * Valores por defecto seguros para configuración NO sensible. Nunca
     * incluye credenciales reales (DB_USER/DB_PASS/JWT_SECRET no tienen
     * default -- no existe un valor "seguro" para adivinar un secreto).
     */
    private static $defaults = [
        'DB_HOST'               => 'localhost',
        'DB_CHARSET'            => 'utf8mb4',
        'JWT_TTL_SECONDS'       => '3600',
        'CORS_ALLOWED_ORIGINS'  => 'https://v2.fidepaz.org',
        'APP_DEBUG'             => 'false',
    ];

    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }

        // Si falta .env en el servidor, se intenta crear una copia local desde
        // .env.example para que la app NUNCA truene por falta del archivo.
        // Las credenciales de ejemplo no sirven para conectar a MySQL real,
        // pero eso ahora falla de forma LIMPIA (JSON 500 controlado en
        // Database::connection), no con una página en blanco.
        if (!is_file($path)) {
            $example = $path . '.example';
            if (is_file($example)) {
                @copy($example, $path);
                error_log('[fidepaz_v2] .env no encontrado; se copió .env.example automáticamente como red de seguridad. Reemplázalo con credenciales reales.');
            }
        }

        if (is_file($path)) {
            $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($lines)) {
                foreach ($lines as $lineIndex => $line) {
                    if ($lineIndex === 0) {
                        // Defensa contra BOM UTF-8 (EF BB BF) si el .env se guardó con
                        // "UTF-8 con BOM" desde algún editor -- si no se limpia, esos 3
                        // bytes invisibles se pegan al KEY de la primera línea.
                        $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
                    }
                    $line = trim($line);
                    if ($line === '' || substr($line, 0, 1) === '#' || strpos($line, '=') === false) {
                        continue;
                    }
                    $parts = explode('=', $line, 2);
                    $key = trim($parts[0]);
                    $value = trim($parts[1]);
                    $value = self::stripWrappingQuotes($value);

                    if ($key !== '' && getenv($key) === false) {
                        putenv("{$key}={$value}");
                        $_ENV[$key] = $value;
                    }
                }
            }
        }

        self::$loaded = true;
    }

    /**
     * Desenvuelve comillas SOLO si el primer y último byte del valor son
     * ambos comilla doble o ambos comilla simple. Estricto a propósito:
     * NUNCA toca ningún otro carácter, sin importar cuál sea (paréntesis,
     * guiones, símbolos, etc.) -- si el valor no está envuelto en un par
     * de comillas completo, se devuelve exactamente como llegó, byte por
     * byte.
     */
    private static function stripWrappingQuotes(string $value): string
    {
        $len = strlen($value);
        if ($len < 2) {
            return $value; // muy corto para tener un par de comillas envolvente
        }

        $firstByte = $value[0];
        $lastByte = $value[$len - 1];
        $isDoubleQuoted = ($firstByte === '"' && $lastByte === '"');
        $isSingleQuoted = ($firstByte === "'" && $lastByte === "'");

        if (!$isDoubleQuoted && !$isSingleQuoted) {
            return $value; // sin comillas envolventes -> se conserva tal cual
        }

        // substr($value, 1, $len - 2) toma todo EXCEPTO el primer y último byte.
        // Con $len == 2 (ej. `""`) devuelve correctamente cadena vacía.
        return substr($value, 1, $len - 2);
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return $value;
        }
        if (isset(self::$defaults[$key])) {
            return self::$defaults[$key];
        }
        return $default;
    }

    /** Corta con un JSON 500 legible (nunca una página en blanco) si falta una variable REQUERIDA. */
    public static function required(string $key): string
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode([
                'status'  => 'error',
                'message' => "Configuración incompleta: falta {$key}. Crea api/v2/.env a partir de api/v2/.env.example con credenciales reales.",
            ]);
            exit;
        }
        return $value;
    }
}
