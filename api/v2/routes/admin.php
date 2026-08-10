<?php
declare(strict_types=1);

/**
 * POST /admin/test-email { "to": "..." } — Propuesta de valor #2, endpoint
 * de diagnóstico (2026-08-10). Restringido a `super_admin` (no
 * admin/super_admin como el resto de operaciones administrativas) porque
 * expone en su respuesta el detalle crudo del handshake SMTP -- incluye
 * las respuestas del servidor de correo, que pueden filtrar información
 * de infraestructura si SMTP está mal configurado (ej. mensajes de error
 * con rutas internas del proveedor). Nunca expone `SMTP_PASS` en la
 * respuesta, solo el resultado del handshake.
 *
 * Uso previsto: una vez que el Capitán configure SMTP_HOST/SMTP_USER/
 * SMTP_PASS reales en api/v2/.env, llamar esta ruta con un correo de
 * prueba propio para confirmar que el handshake de red completo (TCP →
 * TLS → AUTH → envío) funciona antes de activar
 * /auth/forgot-password en producción.
 */
function handle_admin_test_email(): void
{
    $claims = Auth::requireUser();
    Auth::requireRole($claims, ['super_admin']);

    $body = json_decode(file_get_contents('php://input') ?: '', true) ?? [];
    $to = trim((string) ($body['to'] ?? ''));

    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        Response::error(400, 'to es obligatorio y debe ser un email válido');
    }

    $result = Mailer::sendWithDiagnostics(
        $to,
        'Prueba de correo — FidePaz',
        '<p>Este es un correo de prueba del endpoint de diagnóstico SMTP de FidePaz V2.0.</p>'
        . '<p>Si lo recibiste, el handshake de red (' . htmlspecialchars($to, ENT_QUOTES, 'UTF-8') . ') funciona correctamente.</p>'
    );

    Response::json($result['success'] ? 200 : 502, [
        'status' => $result['success'] ? 'ok' : 'error',
        'message' => $result['success'] ? 'Correo de prueba enviado.' : 'El envío de prueba falló -- revisa "steps" para ver en qué paso.',
        'transport' => $result['transport'],
        'steps' => $result['steps'],
    ]);
}
