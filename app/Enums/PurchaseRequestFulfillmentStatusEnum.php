<?php

declare(strict_types=1);

namespace App\Enums;

enum PurchaseRequestFulfillmentStatusEnum: string
{
    case Unfulfilled = 'unfulfilled';
    case PartiallyOrdered = 'partially_ordered';
    case FullyOrdered = 'fully_ordered';

    public function label(): string
    {
        return match ($this) {
            self::Unfulfilled => 'Belum Dipesan',
            self::PartiallyOrdered => 'Dipesan Sebagian',
            self::FullyOrdered => 'Selesai Dipesan',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Unfulfilled => 'neutral',
            self::PartiallyOrdered => 'warning',
            self::FullyOrdered => 'success',
        };
    }
}
