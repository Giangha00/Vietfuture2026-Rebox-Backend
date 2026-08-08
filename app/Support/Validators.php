<?php

namespace App\Support;

class Validators
{
    public static function normalizeEmail(?string $email): string
    {
        return strtolower(trim((string) $email));
    }

    public static function normalizePhone(?string $phone): string
    {
        return preg_replace('/\D+/', '', (string) $phone) ?? '';
    }

    public static function isValidPhone(?string $phone, bool $required = true): bool
    {
        $digits = self::normalizePhone($phone);
        if ($digits === '') {
            return ! $required;
        }

        return strlen($digits) === 10;
    }

    public static function isValidPassword(?string $password): bool
    {
        $password = (string) $password;
        if (strlen($password) < 8) {
            return false;
        }

        return (bool) preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).+$/', $password);
    }

    public static function emptyAddress(): array
    {
        return [
            'fullName' => '',
            'phone' => '',
            'line1' => '',
            'line2' => '',
            'city' => '',
            'district' => '',
            'note' => '',
        ];
    }

    public static function normalizeAddress(?array $address): array
    {
        $base = self::emptyAddress();
        if (! is_array($address)) {
            return $base;
        }

        return [
            'fullName' => trim((string) ($address['fullName'] ?? '')),
            'phone' => self::normalizePhone($address['phone'] ?? ''),
            'line1' => trim((string) ($address['line1'] ?? '')),
            'line2' => trim((string) ($address['line2'] ?? '')),
            'city' => trim((string) ($address['city'] ?? '')),
            'district' => trim((string) ($address['district'] ?? '')),
            'note' => mb_substr(trim((string) ($address['note'] ?? '')), 0, 300),
        ];
    }
}
