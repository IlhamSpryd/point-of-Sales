<?php

declare(strict_types=1);

namespace App\Support\Format;

class Rupiah
{
    /**
     * Format a numeric value as Indonesian Rupiah using UTF-8 NBSP separator.
     *
     * @param  bool  $withCents  When true, format with 2 decimal places.
     */
    public static function format(int|float|string $amount, bool $withCents = false): string
    {
        $num = (float) $amount;
        $prefix = $num < 0 ? "-Rp\u{00A0}" : "Rp\u{00A0}";
        $abs = abs($num);

        if ($withCents) {
            return $prefix.number_format($abs, 2, ',', '.');
        }

        return $prefix.number_format($abs, 0, ',', '.');
    }

    /**
     * Format as a compact label (e.g. "Rp 1,2 jt" for large amounts).
     */
    public static function compact(int|float|string $amount): string
    {
        $num = (float) $amount;

        if (abs($num) >= 1_000_000_000) {
            return "Rp\u{00A0}".number_format($num / 1_000_000_000, 1, ',', '.').' M';
        }

        if (abs($num) >= 1_000_000) {
            return "Rp\u{00A0}".number_format($num / 1_000_000, 1, ',', '.').' jt';
        }

        if (abs($num) >= 1_000) {
            return "Rp\u{00A0}".number_format($num / 1_000, 1, ',', '.').' rb';
        }

        return self::format($num);
    }
}
