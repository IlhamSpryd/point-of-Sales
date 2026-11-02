<?php

declare(strict_types=1);

namespace App\Enums;

enum PreparationStatus: string
{
    case Pending = 'pending';
    case Brewing = 'brewing';
    case Ready = 'ready';
    case Served = 'served';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Queued',
            self::Brewing => 'Preparing',
            self::Ready => 'Ready to Serve',
            self::Served => 'Served',
        };
    }

    public function badgeType(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Brewing => 'info',
            self::Ready => 'success',
            self::Served => 'secondary',
        };
    }
}
