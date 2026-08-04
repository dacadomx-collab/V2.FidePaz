<?php
declare(strict_types=1);

/**
 * JWT HS256 mínimo, sin dependencias externas (evita requerir Composer
 * en cPanel). Si el proyecto crece, migrar a firebase/php-jwt es directo:
 * misma superficie de "encode/decode".
 */
final class Jwt
{
    public static function issue(array $claims, string $secret, int $ttlSeconds): string
    {
        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $now = time();
        $payload = $claims + [
            'iat' => $now,
            'exp' => $now + $ttlSeconds,
        ];

        $segments = [
            self::b64url(json_encode($header, JSON_UNESCAPED_UNICODE)),
            self::b64url(json_encode($payload, JSON_UNESCAPED_UNICODE)),
        ];
        $signingInput = implode('.', $segments);
        $signature = hash_hmac('sha256', $signingInput, $secret, true);
        $segments[] = self::b64url($signature);

        return implode('.', $segments);
    }

    /** @return array<string,mixed>|null null si el token es inválido, está mal firmado o expiró */
    public static function verify(string $token, string $secret): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$headerB64, $payloadB64, $sigB64] = $parts;

        $expected = self::b64url(hash_hmac('sha256', "{$headerB64}.{$payloadB64}", $secret, true));
        if (!hash_equals($expected, $sigB64)) {
            return null;
        }

        $payload = json_decode(self::b64urlDecode($payloadB64), true);
        if (!is_array($payload) || !isset($payload['exp']) || time() >= (int) $payload['exp']) {
            return null;
        }

        return $payload;
    }

    private static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
