<?php

// Polyfill fungsi bcmath untuk lingkungan yang tidak memiliki ekstensi bcmath
// (mis. PHP CLI lokal tanpa bcmath.ini). Implementasinya memakai aritmetika
// desimal skala tetap yang cukup untuk kebutuhan POS (skala <= 8).
// Polyfill ini HANYA aktif bila ekstensi bcmath benar-benar tidak tersedia.

if (! function_exists('bcadd')) {
    function bc_math_parse(string $number, int $scale): array
    {
        $number = trim($number);
        if ($number === '' || ! preg_match('/^([+-]?)(\d*)(?:\.(\d*))?$/', $number, $m)) {
            throw new \ValueError("bcmath: nilai tidak valid: '{$number}'");
        }
        $sign = $m[1] === '-' ? -1 : 1;
        $int = $m[2] === '' ? '0' : $m[2];
        $frac = ($m[3] ?? '') ?: '';
        // Pad/crop pecahan ke scale yang diminta.
        $frac = str_pad(substr($frac, 0, $scale), $scale, '0');
        return [$sign, $int, $frac, $scale];
    }

    function bc_math_cmp(string $a, string $b, int $scale = 0): int
    {
        $scale = max(0, $scale);
        [$sa, $ia, $fa] = bc_math_parse($a, $scale);
        [$sb, $ib, $fb] = bc_math_parse($b, $scale);
        $na = ($sa * (int) $ia * (10 ** $scale)) + ($sa * (int) $fa);
        $nb = ($sb * (int) $ib * (10 ** $scale)) + ($sb * (int) $fb);
        return $na <=> $nb;
    }

    function bc_math_op(string $a, string $b, int $scale, int $signB): string
    {
        $scale = max(0, $scale);
        [$sa, $ia, $fa] = bc_math_parse($a, $scale);
        [$sb, $ib, $fb] = bc_math_parse($b, $scale);
        $na = ((int) $ia * (10 ** $scale)) + (int) $fa;
        $nb = $signB * (((int) $ib * (10 ** $scale)) + (int) $fb);
        $result = $sa * $na + $nb;
        $negative = $result < 0;
        $result = (int) abs($result);
        $divisor = 10 ** $scale;
        $int = intdiv($result, $divisor);
        $frac = str_pad((string) ($result % $divisor), $scale, '0', STR_PAD_LEFT);
        $out = ($negative ? '-' : '').$int.($scale > 0 ? '.'.$frac : '');
        return bc_math_trim($out);
    }

    function bc_math_trim(string $number): string
    {
        if (str_contains($number, '.')) {
            $number = rtrim(rtrim($number, '0'), '.');
            if (str_ends_with($number, '.') || $number === '' || $number === '-') {
                $number = rtrim($number, '.');
            }
        }
        if ($number === '' || $number === '-') {
            return '0';
        }
        return $number;
    }

    function bcadd(string $num1, string $num2, ?int $scale = null): string
    {
        $s = $scale ?? 0;
        return bc_math_op($num1, $num2, $s, +1);
    }

    function bcsub(string $num1, string $num2, ?int $scale = null): string
    {
        $s = $scale ?? 0;
        return bc_math_op($num1, $num2, $s, -1);
    }

    function bcmul(string $num1, string $num2, ?int $scale = null): string
    {
        $s = $scale ?? 0;
        [$sa, $ia1, $fa1, $sc1] = bc_math_parse($num1, $s + 8);
        [$sb, $ia2, $fa2, $sc2] = bc_math_parse($num2, $s + 8);
        $p1 = (int) $ia1 * (10 ** ($s + 8)) + (int) $fa1;
        $p2 = (int) $ia2 * (10 ** ($s + 8)) + (int) $fa2;
        $sign = $sa * $sb;
        $neg = $sign < 0;
        // Skala internal kecil (6 digit) agar produk tetap muat dalam int 64-bit.
        $p1 = (int) $ia1 * (10 ** 6) + (int) substr(str_pad($fa1, 6, '0'), 0, 6);
        $p2 = (int) $ia2 * (10 ** 6) + (int) substr(str_pad($fa2, 6, '0'), 0, 6);
        $prodAbs = $p1 * $p2;
        $div = 10 ** 6;
        $q = intdiv($prodAbs, $div);
        $r = $prodAbs % $div;
        if ($r * 2 >= $div) {
            $q++;
        }
        $digits = str_pad((string) $q, $s + 1, '0', STR_PAD_LEFT);
        $int = $s > 0 ? substr($digits, 0, -$s) : $digits;
        $frac = $s > 0 ? substr($digits, -$s) : '';
        $out = ($neg ? '-' : '').($int === '' ? '0' : $int).($s > 0 ? '.'.$frac : '');
        return bc_math_trim($out);
    }

    function bccomp(string $num1, string $num2, ?int $scale = null): int
    {
        return bc_math_cmp($num1, $num2, $scale ?? 0);
    }

    function bcdiv(string $num1, string $num2, ?int $scale = null): string
    {
        $s = $scale ?? 0;
        if (bc_math_cmp($num2, '0', $s + 8) === 0) {
            throw new \DivisionByZeroError('Division by zero');
        }
        [$sa, $ia, $fa] = bc_math_parse($num1, $s + 12);
        [$sb, $ib, $fb] = bc_math_parse($num2, $s + 12);
        $na = ((int) $ia * (10 ** ($s + 12))) + (int) $fa;
        $nb = ((int) $ib * (10 ** ($s + 12))) + (int) $fb;
        $q = intdiv($na * (10 ** $s), $nb);
        $negative = ($sa * $sb) < 0;
        $divisor = 10 ** $s;
        $int = intdiv($q, $divisor);
        $frac = str_pad((string) ($q % $divisor), $s, '0', STR_PAD_LEFT);
        $out = ($negative ? '-' : '').$int.($s > 0 ? '.'.$frac : '');
        return bc_math_trim($out);
    }
}
