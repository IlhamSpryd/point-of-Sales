<?php

declare(strict_types=1);

namespace App\Enums;

enum StockTransferDiscrepancyResolutionEnum: string
{
    case None = 'NONE';
    case ReturnToSource = 'RETURN_TO_SOURCE';
    case WriteOffInTransit = 'WRITE_OFF_IN_TRANSIT';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Tidak Ada Selisih',
            self::ReturnToSource => 'Kembalikan Selisih ke Cabang Asal (Return to Source)',
            self::WriteOffInTransit => 'Hapus Buku / Susut Perjalanan (Write-off in Transit)',
        };
    }
}
