<?php

namespace App\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class JwtService
{
    public function sign(int|string $userId): string
    {
        $secret = (string) config('rebox.jwt_secret');
        $expiresIn = (string) config('rebox.jwt_expires_in', '7d');
        $ttlSeconds = $this->parseExpiresIn($expiresIn);

        $payload = [
            'sub' => (string) $userId,
            'iat' => time(),
            'exp' => time() + $ttlSeconds,
        ];

        return JWT::encode($payload, $secret, 'HS256');
    }

    public function decode(string $token): object
    {
        $secret = (string) config('rebox.jwt_secret');

        return JWT::decode($token, new Key($secret, 'HS256'));
    }

    protected function parseExpiresIn(string $value): int
    {
        if (preg_match('/^(\d+)([smhd])$/', $value, $m)) {
            $n = (int) $m[1];

            return match ($m[2]) {
                's' => $n,
                'm' => $n * 60,
                'h' => $n * 3600,
                'd' => $n * 86400,
                default => 7 * 86400,
            };
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return 7 * 86400;
    }
}
