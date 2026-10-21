<?php

declare(strict_types=1);

namespace App\Enums;

enum SupplierReturnStatusEnum: string
{
    case Draft = 'DRAFT';
    case Posted = 'POSTED';
    case Cancelled = 'CANCELLED';
    case Reversed = 'REVERSED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Posted => 'Sudah Diposting (Posted)',
            self::Cancelled => 'Dibatalkan (Cancelled)',
            self::Reversed => 'Direverse (Reversed)',
        };
    }

    public function badgeType(): string
    {
        return match ($this) {
            self::Draft => 'warning',
            self::Posted => 'success',
            self::Cancelled => 'secondary',
            self::Reversed => 'danger',
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

    public function canReverse(): bool
    {
        return $this === self::Posted;
    }

    public function canCancel(): bool
    {
        return $this === self::Draft;
    }
}
