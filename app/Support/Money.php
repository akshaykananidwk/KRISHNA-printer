<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Integer-paise money helpers.
 *
 * Every amount in this system is an integer number of paise. Floats are never
 * used for money: 0.1 + 0.2 !== 0.3 in binary floating point, and a print shop
 * reconciling 50 locations against gateway settlements cannot absorb that drift.
 */
final class Money
{
    /** Rupees (as a string or float) to paise, half-up. */
    public static function toPaise(int|float|string $rupees): int
    {
        if (is_string($rupees)) {
            $rupees = (float) str_replace([',', '₹', ' '], '', $rupees);
        }
        return (int) round((float) $rupees * 100, 0, PHP_ROUND_HALF_UP);
    }

    public static function toRupees(int $paise): float
    {
        return $paise / 100;
    }

    /** "1,234.50" — Indian digit grouping, no symbol. */
    public static function format(int $paise): string
    {
        $negative = $paise < 0;
        $paise = abs($paise);
        $whole = intdiv($paise, 100);
        $fraction = $paise % 100;
        return ($negative ? '-' : '') . self::groupIndian($whole) . '.' . str_pad((string) $fraction, 2, '0', STR_PAD_LEFT);
    }

    /** "₹1,234.50" */
    public static function formatWithSymbol(int $paise, string $symbol = '₹'): string
    {
        return $symbol . self::format($paise);
    }

    /**
     * Indian grouping: last three digits, then pairs (12,34,567 not 1,234,567).
     */
    public static function groupIndian(int $number): string
    {
        $digits = (string) $number;
        if (strlen($digits) <= 3) {
            return $digits;
        }
        $last3 = substr($digits, -3);
        $rest = substr($digits, 0, -3);
        $grouped = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
        return $grouped . ',' . $last3;
    }

    /**
     * Apply a percentage to a paise amount, rounding half-up.
     * Rate is expressed in basis points internally to keep the multiplication
     * in integer space (18% -> 1800 bp).
     */
    public static function percentage(int $paise, float $percent): int
    {
        $basisPoints = (int) round($percent * 100);
        return intdiv($paise * $basisPoints + 5000, 10000);
    }
}
