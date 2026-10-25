<?php

declare(strict_types=1);

namespace App\Enums;

enum PurchaseCostStatusEnum: string
{
    case Draft = 'draft';
    case Allocated = 'allocated';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Allocated => 'Dialokasikan (Allocated)',
            self::Cancelled => 'Dibatalkan (Cancelled)',
        };
    }
}
