<?php

namespace Zomunk;

final class Money
{
    private const SYMBOLS = ['INR' => '₹', 'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'AED' => 'AED '];

    public static function format(float $amount, string $currency = 'INR'): string
    {
        $symbol = self::SYMBOLS[strtoupper($currency)] ?? (strtoupper($currency) . ' ');
        return $symbol . number_format($amount, 0, '.', ',');
    }
}
