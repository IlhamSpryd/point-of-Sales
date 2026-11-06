<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum PtkpStatusEnum: string
{
    case TK_0 = 'TK_0';
    case TK_1 = 'TK_1';
    case TK_2 = 'TK_2';
    case TK_3 = 'TK_3';
    case K_0 = 'K_0';
    case K_1 = 'K_1';
    case K_2 = 'K_2';
    case K_3 = 'K_3';

    public function label(): string
    {
        return match ($this) {
            self::TK_0 => 'TK/0 (Single, 0 Dependents)',
            self::TK_1 => 'TK/1 (Single, 1 Dependent)',
            self::TK_2 => 'TK/2 (Single, 2 Dependents)',
            self::TK_3 => 'TK/3 (Single, 3 Dependents)',
            self::K_0 => 'K/0 (Married, 0 Dependents)',
            self::K_1 => 'K/1 (Married, 1 Dependent)',
            self::K_2 => 'K/2 (Married, 2 Dependents)',
            self::K_3 => 'K/3 (Married, 3 Dependents)',
        };
    }

    /**
     * Kategori Tarif Efektif Rata-Rata (TER) sesuai PP 58/2023 & PMK 168/2023.
     */
    public function terCategory(): string
    {
        return match ($this) {
            self::TK_0, self::TK_1, self::K_0 => 'A',
            self::TK_2, self::TK_3, self::K_1, self::K_2 => 'B',
            self::K_3 => 'C',
        };
    }

    /**
     * Nilai nominal Penghasilan Tidak Kena Pajak (PTKP) setahun dalam Rupiah.
     */
    public function annualAmount(): int
    {
        return match ($this) {
            self::TK_0 => 54_000_000,
            self::TK_1, self::K_0 => 58_500_000,
            self::TK_2, self::K_1 => 63_000_000,
            self::TK_3, self::K_2 => 67_500_000,
            self::K_3 => 72_000_000,
        };
    }

    public function isMarried(): bool
    {
        return match ($this) {
            self::K_0, self::K_1, self::K_2, self::K_3 => true,
            default => false,
        };
    }

    public function dependentsCount(): int
    {
        return match ($this) {
            self::TK_0, self::K_0 => 0,
            self::TK_1, self::K_1 => 1,
            self::TK_2, self::K_2 => 2,
            self::TK_3, self::K_3 => 3,
        };
    }
}
