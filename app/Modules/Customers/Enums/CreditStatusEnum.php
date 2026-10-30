<?php

declare(strict_types=1);

namespace App\Modules\Customers\Enums;

enum CreditStatusEnum: string
{
    case ACTIVE = 'ACTIVE';
    case ON_HOLD = 'ON_HOLD';
    case SUSPENDED = 'SUSPENDED';

    public function isBlocked(): bool
    {
        return in_array($this, [self::ON_HOLD, self::SUSPENDED], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Active',
            self::ON_HOLD => 'On Hold',
            self::SUSPENDED => 'Suspended',
        };
    }
}
