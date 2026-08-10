<?php
declare(strict_types=1);

/**
 * Módulo de correo ligero (2026-08-10, Propuesta de valor #2 aprobada —
 * recuperación de contraseña y recordatorios de vencimiento). Sin
 * dependencias nuevas (nada de PHPMailer/Composer) -- usa `mail()`, la
 * función nativa de PHP, que en este hosting cPanel compartido ya tiene
 * sendmail configurado (mismo mecanismo que usan la mayoría de scripts de
 * contacto en cPanel sin configuración adicional).
 *
 * ESTADO 2026-08-10: código preparado, NO probado contra un envío real --
 * este entorno no tiene forma de verificar que un correo realmente llegó a
 * una bandeja de entrada. Antes de darlo por "funcional" hace falta una
 * prueba real (enviar un correo de prueba a una cuenta real y confirmar
 * que llega, revisando también spam). No se activó ninguna ruta que
 * dependa de esto en index.php todavía -- ver auth.php,
 * handle_auth_forgot_password()/handle_auth_reset_password().
 */
final class Mailer
{
    /**
     * @return bool true si mail() aceptó el mensaje para entrega (no
     *              garantiza que llegó -- mail() nunca lo garantiza, es
     *              "fire and forget" hacia el MTA local).
     */
    public static function send(string $to, string $subject, string $htmlBody): bool
    {
        $fromEmail = Env::get('MAIL_FROM_ADDRESS', 'no-reply@fidepaz.org');
        $fromName = Env::get('MAIL_FROM_NAME', 'FidePaz');

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . self::encodeHeader($fromName) . ' <' . $fromEmail . '>',
        ];

        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        return @mail($to, $encodedSubject, $htmlBody, implode("\r\n", $headers));
    }

    private static function encodeHeader(string $value): string
    {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
