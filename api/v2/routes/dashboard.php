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
