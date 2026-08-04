<?php
declare(strict_types=1);

/** Conexión PDO única, siempre con prepared statements (sin excepción -> sin inyección SQL). */
final class Database
{
    private static ?PDO $instance = null;

    public static function connection(): PDO
    {
        if (self::$instance instanceof PDO) {
            return self::$instance;
        }

        $host    = Env::required('DB_HOST');
        $dbName  = Env::required('DB_NAME');
        $user    = Env::required('DB_USER');
        $pass    = Env::required('DB_PASS');
        $charset = Env::get('DB_CHARSET', 'utf8mb4');

        $dsn = "mysql:host={$host};dbname={$dbName};charset={$charset}";

        try {
            self::$instance = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false, // fuerza prepared statements reales del driver
            ]);
        } catch (PDOException $e) {
            error_log('[fidepaz_v2] DB connection error (' . $e->getCode() . '): ' . $e->getMessage());
            if (function_exists('fidepaz_json_fail')) {
                fidepaz_json_fail(502, 'No se pudo conectar a la base de datos', $e);
            } else {
                http_response_code(502);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['status' => 'error', 'message' => 'No se pudo conectar a la base de datos']);
            }
            exit;
        }

        return self::$instance;
    }
}
