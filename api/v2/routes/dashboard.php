<?php
declare(strict_types=1);

/**
 * GET /dashboard/summary — KPIs para la pantalla de inicio del panel
 * (admin/super_admin). Propuesta de valor #3 aprobada 2026-08-10: ni el
 * bundle Angular viejo ni panel/ tenían una pantalla de inicio con
 * métricas -- se entraba directo a Propietarios. Todas las consultas usan
 * los índices ya existentes en `user_quotas`
 * (idx_uq_due_date, idx_uq_pay_date, idx_uq_status) -- ver
 * 02_CODEX_Y_SCHEMA_MAESTRO.md, no se agregó ningún índice nuevo.
 */
function handle_dashboard_summary(): void
{
    $claims = Auth::requireUser();
    Auth::requireRole($claims, ['admin', 'super_admin']);

    $pdo = Database::connection();

    $totalPendiente = (float) $pdo->query(
        'SELECT COALESCE(SUM(amount), 0) FROM user_quotas WHERE status = 1'
    )->fetchColumn();

    $totalPagadoMes = (float) $pdo->query(
        "SELECT COALESCE(SUM(amount), 0) FROM user_quotas
         WHERE status = 2 AND pay_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
           AND pay_date < DATE_FORMAT(CURDATE() + INTERVAL 1 MONTH, '%Y-%m-01')"
    )->fetchColumn();

    $totalPagadoHistorico = (float) $pdo->query(
        'SELECT COALESCE(SUM(amount), 0) FROM user_quotas WHERE status = 2'
    )->fetchColumn();

    $cuotasVencidas = (int) $pdo->query(
        'SELECT COUNT(*) FROM user_quotas WHERE status = 1 AND due_date < CURDATE()'
    )->fetchColumn();

    $colonosMorosos = (int) $pdo->query(
        'SELECT COUNT(DISTINCT user_id) FROM user_quotas WHERE status = 1 AND due_date < CURDATE() AND user_id IS NOT NULL'
    )->fetchColumn();

    $cuotasDelMes = (int) $pdo->query(
        "SELECT COUNT(*) FROM user_quotas
         WHERE due_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
           AND due_date < DATE_FORMAT(CURDATE() + INTERVAL 1 MONTH, '%Y-%m-01')"
    )->fetchColumn();

    $propiedadesActivas = (int) $pdo->query(
        'SELECT COUNT(*) FROM property WHERE deleteAt IS NULL'
    )->fetchColumn();

    // Agregados 2026-08-10 (parte 10) para la infografía Chart.js del
    // dashboard: conteo real de colonos activos y de cuotas pagadas (vs
    // `cuotasVencidas` ya existente) para la gráfica de barras "al día vs
    // en mora".
    $totalColonos = (int) $pdo->query(
        'SELECT COUNT(*) FROM `user` WHERE deleteAt IS NULL'
    )->fetchColumn();

    $cuotasPagadasCount = (int) $pdo->query(
        'SELECT COUNT(*) FROM user_quotas WHERE status = 2'
    )->fetchColumn();

    Response::json(200, [
        'status' => 'ok',
        'data' => [
            'totalPendiente' => round($totalPendiente, 2),
            'totalPagadoMesActual' => round($totalPagadoMes, 2),
            'totalPagadoHistorico' => round($totalPagadoHistorico, 2),
            'cuotasVencidas' => $cuotasVencidas,
            'colonosMorosos' => $colonosMorosos,
            'cuotasDelMes' => $cuotasDelMes,
            'propiedadesActivas' => $propiedadesActivas,
            'totalColonos' => $totalColonos,
            'cuotasPagadasCount' => $cuotasPagadasCount,
        ],
    ]);
}

/**
 * GET /dashboard/yearly-trends — tendencia de recaudación por año
 * (admin/super_admin), para la gráfica de líneas de `panel/index.html`.
 * Agrupa PAGADO por `YEAR(pay_date)` (el año en que realmente entró el
 * dinero) y PENDIENTE por `YEAR(due_date)` (el año en que la cuota se
 * originó y sigue sin cobrarse) -- son dos series con distinto criterio
 * de agrupación a propósito: agrupar ambas por `due_date` haría ver como
 * "pagado en 2023" dinero que en realidad se cobró en 2025, y agruparlas
 * por `pay_date` no tiene sentido para lo pendiente (`pay_date` es NULL
 * si no se ha pagado). Sin años fijos hardcodeados -- se devuelven los
 * años reales presentes en la tabla, cualquiera que sea el rango real de
 * datos migrados.
 */
function handle_dashboard_yearly_trends(): void
{
    $claims = Auth::requireUser();
    Auth::requireRole($claims, ['admin', 'super_admin']);

    $pdo = Database::connection();

    $paidByYear = $pdo->query(
        "SELECT YEAR(pay_date) AS y, COALESCE(SUM(amount), 0) AS total
         FROM user_quotas
         WHERE status = 2 AND pay_date IS NOT NULL
         GROUP BY YEAR(pay_date)"
    )->fetchAll(PDO::FETCH_KEY_PAIR);

    $pendingByYear = $pdo->query(
        "SELECT YEAR(due_date) AS y, COALESCE(SUM(amount), 0) AS total
         FROM user_quotas
         WHERE status = 1
         GROUP BY YEAR(due_date)"
    )->fetchAll(PDO::FETCH_KEY_PAIR);

    $years = array_unique(array_merge(array_keys($paidByYear), array_keys($pendingByYear)));
    sort($years, SORT_NUMERIC);

    $trends = array_map(static function ($year) use ($paidByYear, $pendingByYear): array {
        return [
            'year' => (int) $year,
            'pagado' => round((float) ($paidByYear[$year] ?? 0), 2),
            'pendiente' => round((float) ($pendingByYear[$year] ?? 0), 2),
        ];
    }, $years);

    Response::json(200, ['status' => 'ok', 'data' => array_values($trends)]);
}
