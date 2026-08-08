<?php

namespace App\Support;

class Money
{
    public static function assertProductPrice(mixed $price): float
    {
        $value = (float) $price;
        if ($value <= 0) {
            throw new \InvalidArgumentException('Price must be greater than 0.');
        }
        if ($value > 99999999999) {
            throw new \InvalidArgumentException('Price can have at most 11 digits.');
        }

        return round($value, 2);
    }

    public static function calcFees(float $total): array
    {
        $pct = (float) (env('PLATFORM_FEE_PERCENT', 10));
        $platformFee = round($total * ($pct / 100), 2);
        $sellerPayout = round($total - $platformFee, 2);

        return compact('platformFee', 'sellerPayout');
    }

    public static function format(float $amount): string
    {
        $formatted = number_format($amount, 2, ',', '.');

        return '$'.$formatted;
    }
}
