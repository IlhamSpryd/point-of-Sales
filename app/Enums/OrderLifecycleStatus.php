<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderLifecycleStatus: string
{
    case Draft = 'draft';
    case Parked = 'parked';
    case Submitted = 'submitted';
    case Confirmed = 'confirmed';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Parked => 'Parked',
            self::Submitted => 'Submitted',
            self::Confirmed => 'Confirmed',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function badgeType(): string
    {
        return match ($this) {
            self::Draft, self::Parked => 'secondary',
            self::Submitted => 'warning',
            self::Confirmed, self::Completed => 'success',
            self::Cancelled => 'danger',
        };
    }
}
