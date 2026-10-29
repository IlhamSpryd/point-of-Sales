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
            self::ACTIVE => 'Aktif',
            self::ON_HOLD => 'Ditahan (On Hold)',
            self::SUSPENDED => 'Dibekukan (Suspended)',
        };
    }
}
