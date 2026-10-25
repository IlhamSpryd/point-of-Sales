<?php

declare(strict_types=1);

namespace App\Enums;

enum DamageDispositionEnum: string
{
    case ReturnToVendor = 'return_to_vendor';
    case Scrapped = 'scrapped';
    case PendingInspection = 'pending_inspection';
    case Repaired = 'repaired';

    public function label(): string
    {
        return match ($this) {
            self::ReturnToVendor => 'Retur ke Pemasok',
            self::Scrapped => 'Dimusnahkan',
            self::PendingInspection => 'Menunggu Inspeksi',
            self::Repaired => 'Diperbaiki',
        };
    }
}
