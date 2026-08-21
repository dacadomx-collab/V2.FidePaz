<?php
declare(strict_types=1);

/**
 * Generación automática de cuotas del mes -- pensado para correr como Cron
 * Job real de cPanel el día 1 de cada mes (Objetivo 1, 2026-08-13).
 *
 * Uso en cPanel -> Cron Jobs:
 *   0 0 1 * * php /home/mercagee/public_html/v2.fidepaz.org/api/v2/cli/generate_monthly_quotas.php
 * (00:00 del día 1 de cada mes -- estandarizado 2026-08-14, ver
 * docs/MANUAL_SISTEMA_FIDEPAZ_V2.md §5.2; ajustar la ruta real al home del
 * hosting si cambia). El período se calcula solo a partir de la fecha real
 * del servidor -- no requiere parámetro. Para generar un período distinto
 * al actual (ej. reintento manual de un mes pasado), usar
 * POST /api/v2/quota/generate-period autenticado como admin/super_admin,
 * no este script.
 *
 * Bloqueado por partida doble contra ejecución vía HTTP: api/v2/cli/.htaccess
 * (Require all denied) + el chequeo de PHP_SAPI de aquí abajo.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo ejecutable por CLI/cron, no por HTTP.');
}

$root = __DIR__ . '/..';
require $root . '/core/Env.php';
Env::load($root . '/.env');
require $root . '/core/Database.php';
require $root . '/core/Audit.php';
require $root . '/routes/quotas.php';

$period = date('Y-m');

try {
    $pdo = Database::connection();
    $result = generate_quotas_for_period($pdo, $period, false, null);

    echo "[" . date('Y-m-d H:i:s') . "] generate_monthly_quotas OK período={$period} "
        . "creadas={$result['summary']['createdCount']} "
        . "ya_existian={$result['summary']['skippedExistingCount']} "
        . "sin_propietario={$result['summary']['skippedNoOwnerCount']}\n";

    if ($result['summary']['skippedNoOwnerCount'] > 0) {
        echo "ADVERTENCIA: " . $result['summary']['skippedNoOwnerCount']
            . " propiedad(es) sin historial de cuotas, requieren asignación manual: "
            . implode(', ', $result['skippedNoOwner']) . "\n";
    }

    exit(0);
} catch (\Throwable $e) {
    error_log('[fidepaz_v2] generate_monthly_quotas.php falló: ' . $e->getMessage());
    fwrite(STDERR, "[" . date('Y-m-d H:i:s') . "] ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
