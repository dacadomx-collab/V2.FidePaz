<?php
declare(strict_types=1);

/**
 * Módulo de correo ligero (2026-08-10, Propuesta de valor #2 aprobada —
 * recuperación de contraseña y recordatorios de vencimiento). Sin
 * dependencias nuevas (nada de PHPMailer/Composer): si `SMTP_HOST` no
 * está configurado usa `mail()` nativo de PHP (funciona sin configurar
 * nada extra en este hosting cPanel, que ya trae sendmail); si sí está
 * configurado, habla SMTP real por socket (`fsockopen`/`stream_socket_*`)
 * para poder autenticarse contra un proveedor externo real.
 *
 * ESTADO 2026-08-10: la ruta `mail()` es la misma de siempre. La ruta SMTP
 * por socket es código NUEVO, escrito siguiendo la secuencia estándar del
 * protocolo (RFC 5321: EHLO, STARTTLS opcional, AUTH LOGIN, MAIL FROM,
 * RCPT TO, DATA, QUIT) pero **no probada contra un servidor SMTP real**
 * -- este entorno no tiene credenciales de ningún proveedor. Por eso
 * `sendWithDiagnostics()` existe aparte de `send()`: devuelve el detalle
 * de cada paso del handshake (no solo true/false), para que
 * `POST /admin/test-email` pueda mostrar exactamente en qué paso falla si
 * algo no funciona la primera vez que se configuren credenciales reales.
 */
final class Mailer
{
    /** @return bool true si el mensaje se entregó al MTA local o al servidor SMTP. */
    public static function send(string $to, string $subject, string $htmlBody): bool
    {
        $result = self::sendWithDiagnostics($to, $subject, $htmlBody);
        return $result['success'];
    }

    /**
     * @return array{success:bool, transport:string, steps:list<array{step:string,ok:bool,detail:string}>}
     */
    public static function sendWithDiagnostics(string $to, string $subject, string $htmlBody): array
    {
        $smtpHost = trim((string) Env::get('SMTP_HOST', ''));

        if ($smtpHost === '') {
            $ok = self::sendViaPhpMail($to, $subject, $htmlBody);
            return [
                'success' => $ok,
                'transport' => 'mail() nativo de PHP (SMTP_HOST no configurado)',
                'steps' => [
                    ['step' => 'mail()', 'ok' => $ok, 'detail' => $ok ? 'Aceptado por el MTA local (sendmail).' : 'mail() devolvió false -- revisa la configuración de sendmail del hosting.'],
                ],
            ];
        }

        return self::sendViaSmtp($to, $subject, $htmlBody, $smtpHost);
    }

    private static function sendViaPhpMail(string $to, string $subject, string $htmlBody): bool
    {
        $fromEmail = Env::get('SMTP_FROM_EMAIL', Env::get('MAIL_FROM_ADDRESS', 'no-reply@fidepaz.org'));
        $fromName = Env::get('SMTP_FROM_NAME', Env::get('MAIL_FROM_NAME', 'FidePaz'));

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . self::encodeHeader((string) $fromName) . ' <' . $fromEmail . '>',
        ];

        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        return @mail($to, $encodedSubject, $htmlBody, implode("\r\n", $headers));
    }

    /**
     * Cliente SMTP mínimo por socket. Sigue la secuencia estándar RFC 5321.
     * Cada paso se registra en $steps con su respuesta cruda del servidor
     * (recortada) para diagnóstico real, no un booleano ciego.
     */
    private static function sendViaSmtp(string $to, string $subject, string $htmlBody, string $host): array
    {
        $port = (int) Env::get('SMTP_PORT', '587');
        $user = (string) Env::get('SMTP_USER', '');
        $pass = (string) Env::get('SMTP_PASS', '');
        $fromEmail = (string) Env::get('SMTP_FROM_EMAIL', 'no-reply@fidepaz.org');
        $fromName = (string) Env::get('SMTP_FROM_NAME', 'FidePaz');
        $encryption = strtolower((string) Env::get('SMTP_ENCRYPTION', 'tls'));

        $steps = [];
        $addStep = static function (string $step, bool $ok, string $detail) use (&$steps): void {
            $steps[] = ['step' => $step, 'ok' => $ok, 'detail' => mb_substr($detail, 0, 300)];
        };

        $transport = ($encryption === 'ssl') ? 'ssl://' . $host : $host;
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            $transport . ':' . $port,
            $errno,
            $errstr,
            10,
            STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]])
        );

        if ($socket === false) {
            $addStep('connect', false, "No se pudo conectar a {$host}:{$port} -- {$errstr} (errno {$errno})");
            return ['success' => false, 'transport' => 'SMTP (' . $encryption . ') ' . $host . ':' . $port, 'steps' => $steps];
        }
        $addStep('connect', true, "Conectado a {$host}:{$port}");

        $read = static function ($socket): string {
            $data = '';
            while (($line = fgets($socket, 515)) !== false) {
                $data .= $line;
                // Línea final de una respuesta multilínea SMTP: "250 " (espacio,
                // no guion) en la posición 4. Las intermedias son "250-...".
                if (strlen($line) < 4 || $line[3] === ' ') {
                    break;
                }
            }
            return $data;
        };

        $write = static function ($socket, string $command) {
            fwrite($socket, $command . "\r\n");
        };

        $expectCode = static function (string $response, string $expectedCode): bool {
            return strpos($response, $expectedCode) === 0 || strpos($response, "\n{$expectedCode}") !== false;
        };

        try {
            $greeting = $read($socket);
            $ok = $expectCode($greeting, '220');
            $addStep('greeting', $ok, trim($greeting));
            if (!$ok) {
                return ['success' => false, 'transport' => 'SMTP', 'steps' => $steps];
            }

            $localDomain = 'fidepaz.org';
            $write($socket, "EHLO {$localDomain}");
            $ehloResponse = $read($socket);
            $ok = $expectCode($ehloResponse, '250');
            $addStep('EHLO', $ok, trim($ehloResponse));
            if (!$ok) {
                return ['success' => false, 'transport' => 'SMTP', 'steps' => $steps];
            }

            if ($encryption === 'tls') {
                $write($socket, 'STARTTLS');
                $starttlsResponse = $read($socket);
                $ok = $expectCode($starttlsResponse, '220');
                $addStep('STARTTLS', $ok, trim($starttlsResponse));
                if (!$ok) {
                    return ['success' => false, 'transport' => 'SMTP', 'steps' => $steps];
                }

                $cryptoOk = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                $addStep('TLS handshake', $cryptoOk === true, $cryptoOk === true ? 'Cifrado establecido.' : 'stream_socket_enable_crypto() falló.');
                if ($cryptoOk !== true) {
                    return ['success' => false, 'transport' => 'SMTP', 'steps' => $steps];
                }

                // EHLO se repite obligatoriamente después de STARTTLS -- el
                // servidor "olvida" las extensiones anunciadas antes de cifrar.
                $write($socket, "EHLO {$localDomain}");
                $ehloResponse2 = $read($socket);
                $ok = $expectCode($ehloResponse2, '250');
                $addStep('EHLO (post-TLS)', $ok, trim($ehloResponse2));
                if (!$ok) {
                    return ['success' => false, 'transport' => 'SMTP', 'steps' => $steps];
                }
            }

            if ($user !== '') {
                $write($socket, 'AUTH LOGIN');
                $authResponse = $read($socket);
                $ok = $expectCode($authResponse, '334');
                $addStep('AUTH LOGIN', $ok, trim($authResponse));
                if (!$ok) {
                    return ['success' => false, 'transport' => 'SMTP', 'steps' => $steps];
                }

                $write($socket, base64_encode($user));
                $userResponse = $read($socket);
                $ok = $expectCode($userResponse, '334');
                $addStep('AUTH usuario', $ok, trim($userResponse));
                if (!$ok) {
                    return ['success' => false, 'transport' => 'SMTP', 'steps' => $steps];
                }

                $write($socket, base64_encode($pass));
                $passResponse = $read($socket);
                $ok = $expectCode($passResponse, '235');
                $addStep('AUTH contraseña', $ok, trim($passResponse));
                if (!$ok) {
                    return ['success' => false, 'transport' => 'SMTP', 'steps' => $steps];
                }
            }

            $write($socket, "MAIL FROM:<{$fromEmail}>");
            $mailFromResponse = $read($socket);
            $ok = $expectCode($mailFromResponse, '250');
            $addStep('MAIL FROM', $ok, trim($mailFromResponse));
            if (!$ok) {
                return ['success' => false, 'transport' => 'SMTP', 'steps' => $steps];
            }

            $write($socket, "RCPT TO:<{$to}>");
            $rcptResponse = $read($socket);
            $ok = $expectCode($rcptResponse, '250');
            $addStep('RCPT TO', $ok, trim($rcptResponse));
            if (!$ok) {
                return ['success' => false, 'transport' => 'SMTP', 'steps' => $steps];
            }

            $write($socket, 'DATA');
            $dataResponse = $read($socket);
            $ok = $expectCode($dataResponse, '354');
            $addStep('DATA', $ok, trim($dataResponse));
            if (!$ok) {
                return ['success' => false, 'transport' => 'SMTP', 'steps' => $steps];
            }

            $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
            $message = "From: " . self::encodeHeader($fromName) . " <{$fromEmail}>\r\n"
                . "To: <{$to}>\r\n"
                . "Subject: {$encodedSubject}\r\n"
                . "MIME-Version: 1.0\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n"
                . "\r\n"
                . str_replace("\n.", "\n..", $htmlBody) // escape de "." al inicio de línea (fin de DATA en SMTP)
                . "\r\n.";
            $write($socket, $message);
            $sentResponse = $read($socket);
            $ok = $expectCode($sentResponse, '250');
            $addStep('envío del mensaje', $ok, trim($sentResponse));

            $write($socket, 'QUIT');
            @fclose($socket);

            return ['success' => $ok, 'transport' => 'SMTP (' . $encryption . ') ' . $host . ':' . $port, 'steps' => $steps];
        } catch (\Throwable $e) {
            $addStep('excepción', false, $e->getMessage());
            @fclose($socket);
            return ['success' => false, 'transport' => 'SMTP', 'steps' => $steps];
        }
    }

    private static function encodeHeader(string $value): string
    {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
