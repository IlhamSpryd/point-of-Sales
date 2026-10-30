<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum CommissionBasisTypeEnum: string
{
    case PERCENTAGE_OF_SALES = 'PERCENTAGE_OF_SALES';
    case FIXED_PER_TRANSACTION = 'FIXED_PER_TRANSACTION';
    case PRODUCT_SPECIFIC = 'PRODUCT_SPECIFIC';
    case TIERED_TARGET = 'TIERED_TARGET';
    case SHARED_POOL = 'SHARED_POOL';

    public function label(): string
    {
        return match ($this) {
            self::PERCENTAGE_OF_SALES => 'Persentase Omzet Penjualan',
            self::FIXED_PER_TRANSACTION => 'Nominal Tetap Per Transaksi',
            self::PRODUCT_SPECIFIC => 'Insentif Khusus Produk Promo',
            self::TIERED_TARGET => 'Komisi Berjenjang Target Omzet',
            self::SHARED_POOL => 'Kolam Komisi Bersama Tim Toko',
        };
    }
}
