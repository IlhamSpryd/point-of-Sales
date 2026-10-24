<?php

declare(strict_types=1);

namespace App\Enums;

enum StockOpnameDriftResolutionEnum: string
{
    case None = 'NONE';
    case ReSnapshot = 'RE_SNAPSHOT';
    case RebaseOverride = 'REBASE_OVERRIDE';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Tidak Ada Resolusi (Tolak)',
            self::ReSnapshot => 'Hitung Ulang (Re-Snapshot)',
            self::RebaseOverride => 'Abaikan & Rebase Terhadap Stok Terkini (Rebase Override)',
        };
    }
}
