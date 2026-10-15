<?php

declare(strict_types=1);

namespace App\Enums;

enum PreparationStatus: string
{
    case Pending = 'pending';
    case Brewing = 'brewing';
    case Ready = 'ready';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Menunggu Dapur',
            self::Brewing => 'Sedang Diracik',
            self::Ready => 'Siap Disajikan',
        };
    }

    public function badgeType(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Brewing => 'info',
            self::Ready => 'success',
        };
    }
}
