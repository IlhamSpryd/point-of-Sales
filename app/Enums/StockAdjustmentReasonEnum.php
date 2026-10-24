<?php

declare(strict_types=1);

namespace App\Enums;

enum StockAdjustmentReasonEnum: string
{
    case Damaged = 'DAMAGED';
    case Expired = 'EXPIRED';
    case Shrinkage = 'SHRINKAGE';
    case ProductionWaste = 'PRODUCTION_WASTE';
    case Found = 'FOUND';
    case OpeningStock = 'OPENING_STOCK';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::Damaged => 'Barang Rusak (Damaged)',
            self::Expired => 'Kedaluwarsa (Expired)',
            self::Shrinkage => 'Kehilangan / Selisih Susut (Shrinkage)',
            self::ProductionWaste => 'Limbah Produksi (Production Waste)',
            self::Found => 'Barang Ditemukan (Found)',
            self::OpeningStock => 'Saldo Awal (Opening Stock)',
            self::Other => 'Lainnya (Other)',
        };
    }

    /**
     * Arah mutasi default ('IN' atau 'OUT').
     */
    public function defaultDirection(): string
    {
        return match ($this) {
            self::Found, self::OpeningStock => 'IN',
            self::Damaged, self::Expired, self::Shrinkage, self::ProductionWaste, self::Other => 'OUT',
        };
    }

    /**
     * Memeriksa apakah alasan ini merepresentasikan pemborosan/kerusakan fisik (Waste).
     */
    public function isWaste(): bool
    {
        return in_array($this, [self::Damaged, self::Expired, self::ProductionWaste], true);
    }
}
