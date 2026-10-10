<?php

namespace App\Services;

class JwtService
{
    private static function base64UrlEncode(string $data): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }

    private static function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(str_replace(['-', '_'], ['+', '/'], $data));
    }

    public static function generateToken(string $userId, string $role, int $ttl = 28800): string
    {
        $secret = env('JWT_SECRET', 'default-jwt-secret-key-3413974');
        $now = time();

        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $payload = [
            'sub' => $userId,
            'role' => $role,
            'iat' => $now,
            'exp' => $now + $ttl,
        ];

        $encodedHeader = self::base64UrlEncode(json_encode($header));
        $encodedPayload = self::base64UrlEncode(json_encode($payload));

        $signature = hash_hmac('sha256', "{$encodedHeader}.{$encodedPayload}", $secret, true);
        $encodedSignature = self::base64UrlEncode($signature);

        return "{$encodedHeader}.{$encodedPayload}.{$encodedSignature}";
    }

    public static function validateToken(string $token): ?array
    {
        $secret = env('JWT_SECRET', 'default-jwt-secret-key-3413974');
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $signature = self::base64UrlDecode($encodedSignature);
        $expectedSignature = hash_hmac('sha256', "{$encodedHeader}.{$encodedPayload}", $secret, true);

        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }

        $payload = json_decode(self::base64UrlDecode($encodedPayload), true);
        if (!$payload || !isset($payload['exp']) || $payload['exp'] < time()) {
            return null;
        }

        return $payload;
    }
}

