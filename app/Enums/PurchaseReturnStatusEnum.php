<?php

declare(strict_types=1);

namespace App\Enums;

enum PurchaseReturnStatusEnum: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Posted => 'Sudah Diposting (Posted)',
            self::Cancelled => 'Dibatalkan (Cancelled)',
        };
    }

    public function badgeType(): string
    {
        return match ($this) {
            self::Draft => 'warning',
            self::Posted => 'success',
            self::Cancelled => 'secondary',
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function canPost(): bool
    {
        return $this === self::Draft;
    }

    public function canCancel(): bool
    {
        return $this === self::Draft;
    }
}
