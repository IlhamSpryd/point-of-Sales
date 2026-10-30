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
            self::Pending => 'Queued',
            self::Brewing => 'Preparing',
            self::Ready => 'Ready to Serve',
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
