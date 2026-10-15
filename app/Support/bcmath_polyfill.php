<?php

// Polyfill fungsi bcmath untuk lingkungan yang tidak memiliki ekstensi bcmath
// (mis. PHP CLI lokal tanpa bcmath.ini). Menggunakan aritmetika integer ter-skala
// (scaled integer arithmetic dengan intdiv / PHP_ROUND_HALF_UP) untuk menjaga
// presisi floating-point pada skala desimal POS & BOM/resep (hingga 4-8 desimal).
// Polyfill ini HANYA aktif bila ekstensi bcmath benar-benar tidak tersedia.

if (! extension_loaded('bcmath')) {
    if (! function_exists('_bc_parse')) {
        function _bc_parse(string $number): array
        {
            $number = trim($number);
            if ($number === '' || ! preg_match('/^([+-]?)(\d*)(?:\.(\d*))?$/', $number, $m)) {
                throw new \ValueError("bcmath: nilai numerik tidak valid: '{$number}'");
            }
            $sign = $m[1] === '-' ? -1 : 1;
            $int = $m[2] === '' ? '0' : ltrim($m[2], '0');
            if ($int === '') {
                $int = '0';
            }
            $frac = $m[3] ?? '';
            return [$sign, $int, $frac];
        }

        function _bc_format(int $sign, string $digits, int $dec, int $scale): string
        {
            $scale = max(0, $scale);

            if ($dec > $scale) {
                // Skala dipotong dengan pembulatan PHP_ROUND_HALF_UP
                $drop = $dec - $scale;
                $len = strlen($digits);
                if ($len <= $drop) {
                    $remStr = str_pad($digits, $drop, '0', STR_PAD_LEFT);
                    $keepStr = '0';
                } else {
                    $keepStr = substr($digits, 0, $len - $drop);
                    $remStr = substr($digits, $len - $drop);
                }

                $firstDrop = (int) $remStr[0];
                if ($firstDrop >= 5) {
                    // Bulatkan ke atas
                    $keepLen = strlen($keepStr);
                    $carry = 1;
                    $keepChars = str_split($keepStr);
                    for ($k = $keepLen - 1; $k >= 0 && $carry > 0; $k--) {
                        $digitVal = (int) $keepChars[$k] + $carry;
                        if ($digitVal >= 10) {
                            $keepChars[$k] = (string) ($digitVal - 10);
                            $carry = 1;
                        } else {
                            $keepChars[$k] = (string) $digitVal;
                            $carry = 0;
                        }
                    }
                    if ($carry > 0) {
                        array_unshift($keepChars, '1');
                    }
                    $digits = implode('', $keepChars);
                } else {
                    $digits = $keepStr;
                }
                $dec = $scale;
            } elseif ($dec < $scale) {
                $digits .= str_repeat('0', $scale - $dec);
                $dec = $scale;
            }

            if ($dec === 0) {
                $clean = ltrim($digits, '0');
                if ($clean === '') {
                    return '0';
                }
                return ($sign === -1 ? '-' : '').$clean;
            }

            $len = strlen($digits);
            if ($len <= $dec) {
                $intPart = '0';
                $fracPart = str_pad($digits, $dec, '0', STR_PAD_LEFT);
            } else {
                $intPart = substr($digits, 0, $len - $dec);
                $fracPart = substr($digits, $len - $dec);
            }

            $intClean = ltrim($intPart, '0');
            if ($intClean === '') {
                $intClean = '0';
            }

            if ($intClean === '0' && ltrim($fracPart, '0') === '') {
                $sign = 1;
            }

            return ($sign === -1 ? '-' : '').$intClean.'.'.$fracPart;
        }

        function _bc_str_mul(string $n1, string $n2): string
        {
            $len1 = strlen($n1);
            $len2 = strlen($n2);
            $res = array_fill(0, $len1 + $len2, 0);
            for ($i = $len1 - 1; $i >= 0; $i--) {
                $d1 = (int) $n1[$i];
                for ($j = $len2 - 1; $j >= 0; $j--) {
                    $sum = $d1 * (int) $n2[$j] + $res[$i + $j + 1];
                    $res[$i + $j + 1] = $sum % 10;
                    $res[$i + $j] += intdiv($sum, 10);
                }
            }
            $out = ltrim(implode('', $res), '0');
            return $out === '' ? '0' : $out;
        }
    }

    if (! function_exists('bcadd')) {
        function bcadd(string $num1, string $num2, ?int $scale = null): string
        {
            $s = max(0, $scale ?? 0);
            [$s1, $i1, $f1] = _bc_parse($num1);
            [$s2, $i2, $f2] = _bc_parse($num2);
            $maxDec = max(strlen($f1), strlen($f2), $s);
            $f1 = str_pad($f1, $maxDec, '0');
            $f2 = str_pad($f2, $maxDec, '0');
            $d1 = $i1.$f1;
            $d2 = $i2.$f2;

            if (strlen($d1) < 18 && strlen($d2) < 18) {
                $val1 = $s1 * (int) $d1;
                $val2 = $s2 * (int) $d2;
                $sum = $val1 + $val2;
                $sign = $sum < 0 ? -1 : 1;
                return _bc_format($sign, (string) abs($sum), $maxDec, $s);
            }

            // Fallback float/string jika sangat besar
            $sum = ($s1 * (float) $d1) + ($s2 * (float) $d2);
            $sign = $sum < 0 ? -1 : 1;
            return _bc_format($sign, (string) abs((int) $sum), $maxDec, $s);
        }
    }

    if (! function_exists('bcsub')) {
        function bcsub(string $num1, string $num2, ?int $scale = null): string
        {
            $s = max(0, $scale ?? 0);
            [$s1, $i1, $f1] = _bc_parse($num1);
            [$s2, $i2, $f2] = _bc_parse($num2);
            $maxDec = max(strlen($f1), strlen($f2), $s);
            $f1 = str_pad($f1, $maxDec, '0');
            $f2 = str_pad($f2, $maxDec, '0');
            $d1 = $i1.$f1;
            $d2 = $i2.$f2;

            if (strlen($d1) < 18 && strlen($d2) < 18) {
                $val1 = $s1 * (int) $d1;
                $val2 = (-$s2) * (int) $d2;
                $diff = $val1 + $val2;
                $sign = $diff < 0 ? -1 : 1;
                return _bc_format($sign, (string) abs($diff), $maxDec, $s);
            }

            $diff = ($s1 * (float) $d1) - ($s2 * (float) $d2);
            $sign = $diff < 0 ? -1 : 1;
            return _bc_format($sign, (string) abs((int) $diff), $maxDec, $s);
        }
    }

    if (! function_exists('bcmul')) {
        function bcmul(string $num1, string $num2, ?int $scale = null): string
        {
            $s = max(0, $scale ?? 0);
            [$s1, $i1, $f1] = _bc_parse($num1);
            [$s2, $i2, $f2] = _bc_parse($num2);
            $d1 = $i1.$f1;
            $d2 = $i2.$f2;
            $dec = strlen($f1) + strlen($f2);
            $sign = ($s1 * $s2 === -1) ? -1 : 1;

            if (strlen($d1) < 9 && strlen($d2) < 9) {
                $prod = (int) $d1 * (int) $d2;
                return _bc_format($sign, (string) abs($prod), $dec, $s);
            }

            $prodStr = _bc_str_mul($d1, $d2);
            return _bc_format($sign, $prodStr, $dec, $s);
        }
    }

    if (! function_exists('bcdiv')) {
        function bcdiv(string $num1, string $num2, ?int $scale = null): string
        {
            $s = max(0, $scale ?? 0);
            [$s1, $i1, $f1] = _bc_parse($num1);
            [$s2, $i2, $f2] = _bc_parse($num2);
            $d1 = $i1.$f1;
            $d2 = $i2.$f2;
            $d2Int = (int) $d2;
            if ($d2Int === 0) {
                throw new \DivisionByZeroError('Division by zero');
            }

            $dec1 = strlen($f1);
            $dec2 = strlen($f2);
            // Tambahkan 1 digit untuk pembulatan HALF_UP pada format()
            $shift = $s + 1 + $dec2 - $dec1;
            if ($shift >= 0) {
                $num = (int) $d1 * (10 ** $shift);
                $denom = (int) $d2;
            } else {
                $num = (int) $d1;
                $denom = (int) $d2 * (10 ** (-$shift));
            }
            $q = intdiv($num, $denom);
            $sign = ($s1 * $s2 === -1) ? -1 : 1;
            return _bc_format($sign, (string) abs($q), $s + 1, $s);
        }
    }

    if (! function_exists('bccomp')) {
        function bccomp(string $num1, string $num2, ?int $scale = null): int
        {
            $s = max(0, $scale ?? 0);
            $diff = bcsub($num1, $num2, $s);
            if ($diff === '0' || preg_match('/^0(?:\.0*)?$/', $diff)) {
                return 0;
            }
            return str_starts_with($diff, '-') ? -1 : 1;
        }
    }
}
