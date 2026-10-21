<?php

declare(strict_types=1);

namespace App\Enums;

enum StockTransferStatusEnum: string
{
    case Draft = 'DRAFT';
    case InTransit = 'IN_TRANSIT';
    case Received = 'RECEIVED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::InTransit => 'Dalam Pengiriman (In-Transit)',
            self::Received => 'Diterima (Received)',
            self::Cancelled => 'Dibatalkan (Cancelled)',
        };
    }

    public function badgeType(): string
    {
        return match ($this) {
            self::Draft => 'secondary',
            self::InTransit => 'warning',
            self::Received => 'success',
            self::Cancelled => 'danger',
        };
    }

    public function canShip(): bool
    {
        return $this === self::Draft;
    }

    public function canReceive(): bool
    {
        return $this === self::InTransit;
    }

    public function canCancel(): bool
    {
        return in_array($this, [self::Draft, self::InTransit], true);
    }
}
