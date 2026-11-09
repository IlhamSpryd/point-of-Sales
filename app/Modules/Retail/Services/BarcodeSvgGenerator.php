<?php

declare(strict_types=1);

namespace App\Modules\Retail\Services;

/**
 * BarcodeSvgGenerator
 *
 * Generates pure SVG Code-128 (Subset B) vector barcodes for thermal shelf labels (60x30mm).
 * Does not require any external extensions (Imagick, GD) or third-party cloud APIs.
 */
class BarcodeSvgGenerator
{
    /**
     * Code-128 patterns (widths of bars and spaces, 6 elements each: b1, s1, b2, s2, b3, s3).
     *
     * @var array<int, string>
     */
    private const PATTERNS = [
        0 => '212222', 1 => '222122', 2 => '222221', 3 => '121223', 4 => '121322',
        5 => '131222', 6 => '122213', 7 => '122312', 8 => '132212', 9 => '221213',
        10 => '221312', 11 => '231212', 12 => '112232', 13 => '122132', 14 => '122231',
        15 => '113222', 16 => '123122', 17 => '123221', 18 => '223211', 19 => '221132',
        20 => '221231', 21 => '213212', 22 => '223112', 23 => '312131', 24 => '311222',
        25 => '321122', 26 => '321221', 27 => '312212', 28 => '322112', 29 => '322211',
        30 => '212123', 31 => '212321', 32 => '232121', 33 => '111323', 34 => '131123',
        35 => '131321', 36 => '112313', 37 => '132113', 38 => '132311', 39 => '211313',
        40 => '231113', 41 => '231311', 42 => '112133', 43 => '112331', 44 => '132131',
        45 => '113123', 46 => '113321', 47 => '133121', 48 => '313121', 49 => '211331',
        50 => '231131', 51 => '213113', 52 => '213311', 53 => '213131', 54 => '311123',
        55 => '311321', 56 => '331121', 57 => '312113', 58 => '312311', 59 => '332111',
        60 => '314111', 61 => '221411', 62 => '431111', 63 => '111224', 64 => '111422',
        65 => '121124', 66 => '121421', 67 => '141122', 68 => '141221', 69 => '112214',
        70 => '112412', 71 => '122114', 72 => '122411', 73 => '142112', 74 => '142211',
        75 => '241211', 76 => '221114', 77 => '413111', 78 => '241112', 79 => '134111',
        80 => '111242', 81 => '121142', 82 => '121241', 83 => '114212', 84 => '124112',
        85 => '124211', 86 => '411212', 87 => '421112', 88 => '421211', 89 => '212141',
        90 => '214121', 91 => '412121', 92 => '111143', 93 => '111341', 94 => '131141',
        95 => '114113', 96 => '114311', 97 => '411113', 98 => '411311', 99 => '113141',
        100 => '114131', 101 => '311141', 102 => '411131', 103 => '211412', 104 => '211214',
        105 => '211232', 106 => '2331112',
    ];

    private const START_B = 104;
    private const STOP = 106;

    /**
     * Generate an SVG string representation of a Code-128 barcode.
     */
    public function renderSvg(string $text, int $barHeight = 40, float $moduleWidth = 1.2): string
    {
        $cleanText = trim($text);
        if ($cleanText === '') {
            $cleanText = '000000';
        }

        $codes = [self::START_B];
        $checksum = self::START_B;

        for ($i = 0; $i < strlen($cleanText); $i++) {
            $ascii = ord($cleanText[$i]);
            $value = $ascii - 32;
            if ($value < 0 || $value > 95) {
                $value = 0; // Fallback for unsupported characters
            }
            $codes[] = $value;
            $checksum += $value * ($i + 1);
        }

        $checkDigit = $checksum % 103;
        $codes[] = $checkDigit;
        $codes[] = self::STOP;

        // Build bars
        $bars = [];
        $currentX = 10.0; // Left quiet zone

        foreach ($codes as $code) {
            $pattern = self::PATTERNS[$code];
            $isBar = true;

            for ($p = 0; $p < strlen($pattern); $p++) {
                $width = ((int) $pattern[$p]) * $moduleWidth;
                if ($isBar) {
                    $bars[] = sprintf(
                        '<rect x="%.2f" y="0" width="%.2f" height="%d" fill="#000000"/>',
                        $currentX,
                        $width,
                        $barHeight
                    );
                }
                $currentX += $width;
                $isBar = ! $isBar;
            }
        }

        $totalWidth = ceil($currentX + 10.0); // Right quiet zone
        $totalHeight = $barHeight;

        $barsSvg = implode("\n    ", $bars);

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {$totalWidth} {$totalHeight}" width="100%" height="{$barHeight}px" preserveAspectRatio="none">
    {$barsSvg}
</svg>
SVG;
    }
}
