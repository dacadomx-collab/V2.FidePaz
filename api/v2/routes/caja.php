<?php
declare(strict_types=1);

/**
 * Módulo de Caja / "Registrar Pago" (Objetivo 2, 2026-08-13) -- EXCLUSIVO
 * super_admin (no admin) en todas sus rutas, según lo pedido explícitamente.
 * Búsqueda de colono/propiedad -> matriz de meses pendientes -> registrar
 * pago (transacción atómica: marca pagado, guarda comprobante, genera
 * recibo oficial).
 *
 * Nota de diseño sobre el "recibo oficial en PDF": este stack PHP no usa
 * Composer (ver nota histórica en core/Jwt.php sobre evitar dependencias en
 * cPanel) y no hay ninguna librería de generación de PDF real disponible.
 * En vez de escribir a mano un generador de PDF binario (frágil, fácil de
 * producir un archivo corrupto), el "recibo oficial" es una página HTML
 * limpia e imprimible generada por el servidor -- el botón "Descargar" del
 * panel abre esa página y el navegador la imprime/guarda como PDF nativo
 * (Ctrl+P -> Guardar como PDF). Mismo resultado para el colono (un PDF real
 * en su computadora), sin el riesgo de un writer de PDF hecho a mano.
 */

/**
 * Valida y guarda un comprobante subido (MIME real vía finfo, nunca el
 * Content-Type del navegador), nombre generado server-side. Función de
 * nivel superior (no anidada dentro de un handler) a propósito -- PHP
 * fatal-errorea con "Cannot redeclare function" si una función definida
 * DENTRO de otra se declara dos veces en el mismo proceso; en un handler
 * que puede invocarse más de una vez por request (bucle "individual" de
 * comprobantes) o reutilizarse desde un test, eso hubiera sido un bug real.
 */
function caja_store_uploaded_file(array $file, string $uploadsDir, array $allowedMimeToExt, int $quotaId): string
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        Response::error(400, "No se recibió un comprobante válido para la cuota #{$quotaId}.");
    }
    if ((int) $file['size'] > 5 * 1024 * 1024) {
        Response::error(400, "El comprobante de la cuota #{$quotaId} supera el máximo de 5 MB.");
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $realMime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!isset($allowedMimeToExt[$realMime])) {
        Response::error(400, "El comprobante de la cuota #{$quotaId} no es un PDF/PNG/JPG válido.");
    }
    $name = 'uq' . $quotaId . '_' . bin2hex(random_bytes(16)) . '.' . $allowedMimeToExt[$realMime];
    if (!move_uploaded_file($file['tmp_name'], $uploadsDir . $name)) {
        Response::error(500, 'No se pudo guardar el comprobante en el servidor.');
    }
    return $name;
}

/** GET /caja/search?q= -- busca colono por nombre/correo/código o por calle/número oficial de su propiedad. */
function handle_caja_search(): void
{
    $claims = Auth::requireUser();
    Auth::requireRole($claims, ['super_admin']);

    $q = trim((string) ($_GET['q'] ?? ''));
    if (mb_strlen($q) < 2) {
        Response::json(200, ['status' => 'ok', 'items' => []]);
    }

    $pdo = Database::connection();
    $like = '%' . $q . '%';

    $stmt = $pdo->prepare(
        'SELECT DISTINCT u.id, u.name, u.email, u.code
         FROM `user` u
         LEFT JOIN user_quotas uq ON uq.user_id = u.id
         LEFT JOIN property p ON p.id = uq.property_id
         LEFT JOIN street s ON s.id = p.street_id
         WHERE u.deleteAt IS NULL
           AND (u.name LIKE :q1 OR u.email LIKE :q2 OR u.code LIKE :q3
                OR s.name LIKE :q4 OR p.numOficial LIKE :q5)
         ORDER BY u.name
         LIMIT 20'
    );
    $stmt->execute(['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like, 'q5' => $like]);
    $owners = $stmt->fetchAll();

    $propStmt = $pdo->prepare(
        'SELECT DISTINCT p.id, p.numOficial, s.name AS street_name
         FROM user_quotas uq
         JOIN property p ON p.id = uq.property_id
         LEFT JOIN street s ON s.id = p.street_id
         WHERE uq.user_id = ?'
    );

    $items = array_map(static function (array $owner) use ($propStmt): array {
        $propStmt->execute([$owner['id']]);
        return [
            'id' => $owner['id'],
            'name' => $owner['name'],
            'email' => $owner['email'],
            'code' => $owner['code'],
            'properties' => array_map(static fn (array $p): array => [
                'id' => $p['id'],
                'numOficial' => $p['numOficial'],
                'streetName' => $p['street_name'],
            ], $propStmt->fetchAll()),
        ];
    }, $owners);

    Response::json(200, ['status' => 'ok', 'items' => $items]);
}

/** GET /caja/pending/{userId} -- matriz de meses pendientes de un colono, para seleccionar cuáles cobrar. */
function handle_caja_pending(int $userId): void
{
    $claims = Auth::requireUser();
    Auth::requireRole($claims, ['super_admin']);

    $pdo = Database::connection();

    $userStmt = $pdo->prepare('SELECT id, name, email, code FROM `user` WHERE id = ? AND deleteAt IS NULL');
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch();
    if ($user === false) {
        Response::error(404, 'Colono no encontrado');
    }

    $stmt = $pdo->prepare(
        'SELECT uq.id, uq.due_date, uq.amount, p.numOficial, s.name AS street_name
         FROM user_quotas uq
         LEFT JOIN property p ON p.id = uq.property_id
         LEFT JOIN street s ON s.id = p.street_id
         WHERE uq.user_id = ? AND uq.status = 1
         ORDER BY uq.due_date ASC'
    );
    $stmt->execute([$userId]);

    $items = array_map(static function (array $r): array {
        return [
            'id' => $r['id'],
            'dueDate' => Response::isoDate($r['due_date']),
            'year' => (int) substr((string) $r['due_date'], 0, 4),
            'amount' => (float) $r['amount'],
            'property' => ['numOficial' => $r['numOficial'], 'streetName' => $r['street_name']],
        ];
    }, $stmt->fetchAll());

    Response::json(200, [
        'status' => 'ok',
        'user' => ['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email'], 'code' => $user['code']],
        'items' => $items,
    ]);
}

/**
 * POST /caja/register-payment -- multipart/form-data. Transacción atómica:
 * marca las cuotas seleccionadas como pagadas, guarda comprobante(s) y
 * genera el recibo oficial.
 *
 * Campos:
 *   quotaIds        JSON string, array de IDs de user_quotas (todas deben
 *                    ser del mismo colono y estar en status=1)
 *   payDate          "YYYY-MM-DD" -- fecha real del pago
 *   receiptMode      "single" | "individual"
 *   overrideQuotaId  opcional -- id de `quota` (catálogo) para recalcular
 *                    el monto de TODAS las filas seleccionadas a esa tarifa
 *                    antes de marcarlas pagadas (si no viene, se respeta el
 *                    `amount` que ya tenía cada fila desde que se generó)
 *   file             el comprobante, si receiptMode=single (un archivo para
 *                    todas las filas)
 *   file_{quotaId}   un comprobante por fila, si receiptMode=individual
 */
function handle_caja_register_payment(): void
{
    $claims = Auth::requireUser();
    Auth::requireRole($claims, ['super_admin']);
    $actorId = (int) $claims['sub'];

    $quotaIds = json_decode((string) ($_POST['quotaIds'] ?? '[]'), true);
    if (!is_array($quotaIds) || count($quotaIds) === 0) {
        Response::error(400, 'quotaIds es obligatorio (array no vacío)');
    }
    $quotaIds = array_values(array_unique(array_map('intval', $quotaIds)));

    $payDate = (string) ($_POST['payDate'] ?? '');
    if (!preg_match('#^\d{4}-\d{2}-\d{2}$#', $payDate)) {
        Response::error(400, 'payDate es obligatorio, formato YYYY-MM-DD');
    }

    $receiptMode = (string) ($_POST['receiptMode'] ?? 'single');
    if (!in_array($receiptMode, ['single', 'individual'], true)) {
        Response::error(400, 'receiptMode inválido (single|individual)');
    }

    $pdo = Database::connection();

    $placeholders = implode(',', array_fill(0, count($quotaIds), '?'));
    $rowsStmt = $pdo->prepare(
        "SELECT uq.id, uq.user_id, uq.amount, uq.due_date, uq.status, p.numOficial, s.name AS street_name
         FROM user_quotas uq
         LEFT JOIN property p ON p.id = uq.property_id
         LEFT JOIN street s ON s.id = p.street_id
         WHERE uq.id IN ({$placeholders})"
    );
    $rowsStmt->execute($quotaIds);
    $rows = $rowsStmt->fetchAll();

    if (count($rows) !== count($quotaIds)) {
        Response::error(404, 'Alguna de las cuotas seleccionadas no existe');
    }
    $userIds = array_unique(array_map(static fn (array $r): int => (int) $r['user_id'], $rows));
    if (count($userIds) !== 1) {
        Response::error(400, 'Todas las cuotas seleccionadas deben ser del mismo colono');
    }
    foreach ($rows as $r) {
        if ((int) $r['status'] !== 1) {
            Response::error(400, "La cuota #{$r['id']} ya no está pendiente -- alguien más pudo haberla cobrado, recarga la matriz.");
        }
    }
    $userId = (int) $userIds[0];

    $userStmt = $pdo->prepare('SELECT id, name, email, code FROM `user` WHERE id = ?');
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch();

    // Tarifa de reemplazo opcional (catálogo de Cuotas) -- si se manda,
    // sustituye el amount de TODAS las filas seleccionadas antes de cobrar.
    $overrideAmount = null;
    if (!empty($_POST['overrideQuotaId'])) {
        $quotaStmt = $pdo->prepare('SELECT cost FROM quota WHERE id = ?');
        $quotaStmt->execute([(int) $_POST['overrideQuotaId']]);
        $cost = $quotaStmt->fetchColumn();
        if ($cost === false) {
            Response::error(400, 'overrideQuotaId no corresponde a ningún tipo de cuota real');
        }
        $overrideAmount = (float) $cost;
    }

    // --- Comprobante(s) subido(s) por el admin (MIME real vía finfo, igual
    // que /payment/upload-receipt -- nunca se confía en el nombre/Content-Type
    // que manda el navegador) ---
    $allowedMimeToExt = ['application/pdf' => 'pdf', 'image/png' => 'png', 'image/jpeg' => 'jpg'];
    $uploadsDir = __DIR__ . '/../../../assets/uploads/receipts/';

    $receiptByQuotaId = [];
    if ($receiptMode === 'single') {
        if (!isset($_FILES['file'])) {
            Response::error(400, 'Falta el archivo del comprobante.');
        }
        $singleName = caja_store_uploaded_file($_FILES['file'], $uploadsDir, $allowedMimeToExt, $quotaIds[0]);
        foreach ($quotaIds as $id) {
            $receiptByQuotaId[$id] = $singleName;
        }
    } else {
        foreach ($quotaIds as $id) {
            $fieldName = 'file_' . $id;
            if (!isset($_FILES[$fieldName])) {
                Response::error(400, "Falta el comprobante individual de la cuota #{$id}.");
            }
            $receiptByQuotaId[$id] = caja_store_uploaded_file($_FILES[$fieldName], $uploadsDir, $allowedMimeToExt, $id);
        }
    }

    // --- Recibo oficial (HTML imprimible, ver nota de diseño arriba) ---
    $folio = sprintf('FIDEPAZ-%s-%06d', date('Y'), min($quotaIds));
    $total = 0.0;
    $coveredMonths = [];
    foreach ($rows as $r) {
        $amount = $overrideAmount ?? (float) $r['amount'];
        $total += $amount;
        $coveredMonths[] = [
            'dueDate' => $r['due_date'],
            'amount' => $amount,
            'property' => trim((string) $r['street_name'] . ' #' . $r['numOficial']),
        ];
    }
    $receiptHtml = caja_build_receipt_html($folio, $user, $coveredMonths, $total, $payDate);
    $receiptFileName = 'recibo_' . $folio . '_' . bin2hex(random_bytes(8)) . '.html';
    $receiptsDir = __DIR__ . '/../../../assets/uploads/official_receipts/';
    file_put_contents($receiptsDir . $receiptFileName, $receiptHtml);
    $officialReceiptUrl = 'assets/uploads/official_receipts/' . $receiptFileName;

    // --- Transacción atómica ---
    $pdo->beginTransaction();
    try {
        $updateStmt = $pdo->prepare(
            'UPDATE user_quotas
             SET status = 2, pay_date = ?, captured_at = NOW(), receipt = ?, official_receipt_url = ?' .
            ($overrideAmount !== null ? ', amount = ?' : '') . '
             WHERE id = ?'
        );
        foreach ($rows as $r) {
            $quotaId = (int) $r['id'];
            $before = ['status' => (int) $r['status'], 'amount' => (float) $r['amount']];

            $params = [$payDate, $receiptByQuotaId[$quotaId], $officialReceiptUrl];
            if ($overrideAmount !== null) {
                $params[] = $overrideAmount;
            }
            $params[] = $quotaId;
            $updateStmt->execute($params);

            Audit::log('user_quotas', $quotaId, 'update', $actorId, [
                'before' => $before,
                'after' => [
                    'status' => 2,
                    'amount' => $overrideAmount ?? (float) $r['amount'],
                    'pay_date' => $payDate,
                    'receipt' => $receiptByQuotaId[$quotaId],
                    'official_receipt_url' => $officialReceiptUrl,
                    'source' => 'caja/register-payment',
                    'folio' => $folio,
                ],
            ]);
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    // Notificación automática al colono (2026-08-14, Objetivo 5) -- FUERA
    // de la transacción a propósito: el pago ya quedó confirmado y
    // comprometido (commit arriba); si esto fallara por lo que sea, no debe
    // revertir un cobro real ya hecho. messages_notify_payment() vive en
    // routes/messages.php, disponible aquí porque index.php ya requirió
    // ambos archivos antes de despachar cualquier request.
    $monthsList = implode(', ', array_map(
        static fn (array $m): string => caja_month_name_es((string) $m['dueDate']),
        $coveredMonths
    ));
    $notifySubject = '¡Confirmación de Pago Recibido - FidePaz!';
    $notifyBody = "Hola {$user['name']},\n\n"
        . "¡Gracias por tu aportación! Confirmamos la recepción de tu pago correspondiente a: {$monthsList}.\n\n"
        . 'Monto total: $' . number_format($total, 2) . "\n"
        . "Folio del recibo oficial: {$folio}\n\n"
        . "Puedes descargar tu comprobante y tu recibo oficial en cualquier momento desde tu panel: "
        // Ruta relativa (2026-08-14, corrección) -- antes era un dominio fijo
        // (v2.fidepaz.org) que llevaba a producción sin importar dónde se
        // hubiera registrado el pago; mensajes.html:linkify() ya resuelve
        // rutas que empiezan con "/panel/" contra el origin actual, así que
        // el enlace ahora respeta local/staging/producción.
        . '/panel/mi-cuenta.html' . "\n\n"
        . 'Asociación de Colonos de FidePaz';
    messages_notify_payment($pdo, $userId, $actorId, $notifySubject, $notifyBody);

    Response::json(200, [
        'status' => 'ok',
        'folio' => $folio,
        'total' => $total,
        'quotasPaid' => count($quotaIds),
        'officialReceiptUrl' => $officialReceiptUrl,
    ]);
}

/** Arma el HTML del recibo oficial -- función pura, sin efectos de lado. */
/**
 * Nombres de mes en español para el recibo -- NO usar date('F') aquí:
 * depende del locale del proceso PHP (setlocale), que en cPanel compartido
 * no está garantizado en español y silenciosamente cae a inglés (visto real
 * en la primera prueba de este endpoint: "January 2023" en vez de
 * "Enero 2023"). Un array fijo no depende de configuración del servidor.
 */
function caja_month_name_es(string $mysqlDatetime): string
{
    $meses = [1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
        7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'];
    $ts = strtotime($mysqlDatetime);
    return $meses[(int) date('n', $ts)] . ' ' . date('Y', $ts);
}

/**
 * Contraparte PHP de formatDateColono() (panel/js/api.js, Objetivo 3
 * 2026-08-14) -- mismo formato "DD MMM YYYY" con mes de 3 letras en
 * mayúsculas (ej. "13 AGO 2026"), para el Recibo Oficial que se renderiza
 * server-side. Mismo motivo que caja_month_name_es(): no usar date('M')
 * (depende del locale del proceso PHP, no garantizado en español).
 */
function caja_format_date_colono(string $mysqlDatetime): string
{
    $abrev = [1 => 'ENE', 2 => 'FEB', 3 => 'MAR', 4 => 'ABR', 5 => 'MAY', 6 => 'JUN',
        7 => 'JUL', 8 => 'AGO', 9 => 'SEP', 10 => 'OCT', 11 => 'NOV', 12 => 'DIC'];
    $ts = strtotime($mysqlDatetime);
    if ($ts === false) {
        return '';
    }
    return sprintf('%02d', (int) date('j', $ts)) . ' ' . $abrev[(int) date('n', $ts)] . ' ' . date('Y', $ts);
}

/**
 * Rediseño "banco/Airbnb" (2026-08-13, Objetivo 3) -- mismo folio, mismo
 * $months/$user/$total/$payDate de siempre, solo cambia la presentación.
 * Sigue siendo una función pura sin efectos de lado: la usan tanto
 * handle_caja_register_payment() (guarda el HTML resultante en disco, sin
 * cambios en ese flujo) como el endpoint dinámico nuevo
 * handle_payment_receipt_view() en routes/quotas.php (nunca escribe a
 * disco, solo hace echo del HTML al vuelo). Paleta y radios calcados a mano
 * de las custom properties de assets/css/main.css -- este documento debe
 * quedar 100% autocontenido (nada de <link> externos) porque se sirve
 * desde dos ubicaciones distintas (assets/uploads/official_receipts/*.html
 * y la propia API), así que no hay una ruta relativa a main.css que
 * funcione desde ambas.
 */
/**
 * Logo oficial embebido como data URI (2026-08-14, Objetivo 1) -- el
 * documento debe seguir siendo 100% autocontenido (ver nota arriba), así
 * que un <img src="assets/img/..."> normal no serviría: se rompe según
 * desde cuál de las dos ubicaciones se sirva el HTML. `assets/img/logo.png`
 * no existe en este proyecto -- el archivo real es
 * `assets/img/fidepaz-logo.png` (usado también en el resto del sitio). Con
 * caché estática de proceso: se lee/codifica una sola vez aunque el
 * endpoint dinámico se llame muchas veces en el mismo request-response.
 */
function caja_logo_data_uri(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $path = __DIR__ . '/../../../assets/img/fidepaz-logo.png';
    $cached = is_file($path) ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($path)) : '';
    return $cached;
}

function caja_build_receipt_html(string $folio, array $user, array $months, float $total, string $payDate): string
{
    $rowsHtml = '';
    $propertyLabels = [];
    foreach ($months as $m) {
        $period = caja_month_name_es((string) $m['dueDate']);
        $propertyLabels[(string) $m['property']] = true;
        $rowsHtml .= '<tr>'
            . '<td>' . htmlspecialchars($period, ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td>Cuota de mantenimiento</td>'
            . '<td>' . htmlspecialchars((string) $m['property'], ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td class="rc-amount">$' . number_format($m['amount'], 2) . '</td>'
            . '</tr>';
    }
    // Encabezado "Propiedad" (Objetivo 1): si el recibo cubre una sola
    // propiedad (caso normal) se muestra ahí directo; si por alguna razón
    // cubriera varias en el mismo cobro, se deja claro en vez de mostrar
    // solo la primera como si fuera la única.
    $propertyHeader = count($propertyLabels) === 1
        ? array_key_first($propertyLabels)
        : (count($propertyLabels) . ' propiedades (ver detalle abajo)');

    $name = htmlspecialchars(trim((string) $user['name']), ENT_QUOTES, 'UTF-8');
    $code = htmlspecialchars((string) ($user['code'] ?? ''), ENT_QUOTES, 'UTF-8');
    $email = htmlspecialchars((string) $user['email'], ENT_QUOTES, 'UTF-8');
    $propertyHeaderEsc = htmlspecialchars($propertyHeader, ENT_QUOTES, 'UTF-8');
    $folioEsc = htmlspecialchars($folio, ENT_QUOTES, 'UTF-8');
    $payDateFmt = htmlspecialchars(caja_format_date_colono($payDate), ENT_QUOTES, 'UTF-8');
    $issuedFmt = htmlspecialchars(caja_format_date_colono('now'), ENT_QUOTES, 'UTF-8');
    $totalFmt = number_format($total, 2);
    $logoUri = caja_logo_data_uri();
    $brandHtml = $logoUri !== ''
        ? '<img class="rc-logo" src="' . $logoUri . '" alt="FidePaz">'
        : '<div class="rc-brand-emoji">🏡</div>';

    return <<<HTML
<!doctype html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Recibo {$folioEsc} — FidePaz</title>
<style>
    :root {
        --rc-primary: #1c64f2; --rc-primary-dark: #174baa; --rc-primary-soft: #ebf5ff;
        --rc-success: #0e9f6e; --rc-success-soft: #def7ec;
        --rc-text: #1f2937; --rc-text-muted: #6b7280; --rc-border: #e5e7eb; --rc-bg-alt: #f9f9f9;
    }
    * { box-sizing: border-box; }
    body {
        font-family: -apple-system, "Segoe UI", Roboto, Arial, Helvetica, sans-serif;
        color: var(--rc-text); background: var(--rc-bg-alt);
        max-width: 38rem; margin: 2rem auto; padding: 0 1rem;
    }
    .rc-card {
        background: #ffffff; border: 1px solid var(--rc-border); border-top: 4px solid var(--rc-primary);
        border-radius: 0.75rem; box-shadow: 0 1px 3px rgba(0,0,0,0.08); overflow: hidden;
    }
    .rc-head {
        display: flex; justify-content: space-between; align-items: flex-start;
        padding: 1.5rem; border-bottom: 1px dashed var(--rc-border);
    }
    .rc-brand-row { display: flex; align-items: center; gap: 0.75rem; }
    .rc-logo { height: 2.75rem; width: auto; }
    .rc-brand-emoji { font-size: 2rem; line-height: 1; }
    .rc-brand { font-size: 1.15rem; font-weight: 800; color: var(--rc-primary-dark); }
    .rc-brand-sub { font-size: 0.8rem; color: var(--rc-text-muted); margin-top: 0.15rem; }
    .rc-status {
        display: inline-flex; align-items: center; gap: 0.35rem;
        background: var(--rc-success-soft); color: var(--rc-success);
        font-weight: 700; font-size: 0.8rem; padding: 0.3rem 0.75rem; border-radius: 999px;
    }
    .rc-title { padding: 1.25rem 1.5rem 0; }
    .rc-title h1 { font-size: 1.1rem; margin: 0 0 0.2rem; letter-spacing: 0.02em; }
    .rc-folio { color: var(--rc-text-muted); font-size: 0.85rem; }
    .rc-meta {
        display: flex; flex-wrap: wrap; gap: 1.5rem;
        padding: 1.25rem 1.5rem; font-size: 0.88rem;
    }
    .rc-meta div { flex: 1; min-width: 12rem; }
    .rc-meta .rc-label { color: var(--rc-text-muted); font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.03em; margin-bottom: 0.15rem; }
    table { width: 100%; border-collapse: collapse; }
    th { text-align: left; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.03em; color: var(--rc-text-muted); padding: 0.6rem 1.5rem; background: var(--rc-primary-soft); }
    td { padding: 0.65rem 1.5rem; border-bottom: 1px solid var(--rc-border); font-size: 0.92rem; }
    .rc-amount { text-align: right; font-variant-numeric: tabular-nums; }
    .rc-total-row td { font-weight: 800; font-size: 1.05rem; border-top: 2px solid var(--rc-text); border-bottom: none; }
    .rc-foot { padding: 1.25rem 1.5rem 1.5rem; font-size: 0.78rem; color: var(--rc-text-muted); border-top: 1px dashed var(--rc-border); }
    .rc-actions { display: flex; justify-content: center; gap: 0.75rem; margin-top: 1.25rem; }
    .rc-btn {
        font: inherit; font-size: 0.85rem; font-weight: 700; padding: 0.55rem 1.15rem;
        border-radius: 0.5rem; cursor: pointer; border: 1px solid var(--rc-border);
        background: #ffffff; color: var(--rc-text);
    }
    .rc-btn.primary { background: var(--rc-primary); border-color: var(--rc-primary); color: #ffffff; }
    .rc-print-hint { text-align: center; margin-top: 0.75rem; font-size: 0.78rem; color: var(--rc-text-muted); }
    @media print {
        body { background: #ffffff; margin: 0; max-width: none; }
        .rc-card { border: none; box-shadow: none; border-radius: 0; }
        .rc-actions, .rc-print-hint { display: none; }
    }
</style>
</head>
<body>
    <div class="rc-card">
        <div class="rc-head">
            <div class="rc-brand-row">
                {$brandHtml}
                <div>
                    <div class="rc-brand">FidePaz</div>
                    <div class="rc-brand-sub">Asociación de Colonos</div>
                </div>
            </div>
            <span class="rc-status">✅ PAGADO</span>
        </div>
        <div class="rc-title">
            <h1>RECIBO OFICIAL DE PAGO</h1>
            <div class="rc-folio">Folio: <strong>{$folioEsc}</strong> · Emitido: {$issuedFmt}</div>
        </div>
        <div class="rc-meta">
            <div>
                <div class="rc-label">Colono</div>
                {$name} ({$code})
            </div>
            <div>
                <div class="rc-label">Correo</div>
                {$email}
            </div>
            <div>
                <div class="rc-label">Propiedad</div>
                {$propertyHeaderEsc}
            </div>
            <div>
                <div class="rc-label">Fecha de pago</div>
                {$payDateFmt}
            </div>
        </div>
        <table>
            <thead><tr><th>Periodo</th><th>Concepto</th><th>Propiedad</th><th class="rc-amount">Monto</th></tr></thead>
            <tbody>{$rowsHtml}</tbody>
            <tfoot><tr class="rc-total-row"><td colspan="3">Total pagado</td><td class="rc-amount">\${$totalFmt}</td></tr></tfoot>
        </table>
        <div class="rc-foot">Este recibo se genera automáticamente por el sistema FidePaz V2.0 a partir del registro de pago. Documento informativo, no fiscal.</div>
    </div>
    <div class="rc-actions">
        <button type="button" class="rc-btn primary" id="rc-btn-print">🖨️ Imprimir / Guardar en PDF</button>
        <button type="button" class="rc-btn" id="rc-btn-close">✖️ Cerrar / Volver al Panel</button>
    </div>
    <p class="rc-print-hint">Para guardarlo como PDF, usa el botón de arriba o Ctrl+P (Cmd+P en Mac) → "Guardar como PDF".</p>
    <script>
        document.getElementById('rc-btn-print').addEventListener('click', function () { window.print(); });
        document.getElementById('rc-btn-close').addEventListener('click', function () {
            window.close();
            setTimeout(function () { window.location.href = '/panel/'; }, 300);
        });
    </script>
</body>
</html>
HTML;
}
