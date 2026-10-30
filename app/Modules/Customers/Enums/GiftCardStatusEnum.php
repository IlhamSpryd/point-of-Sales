<?php

declare(strict_types=1);

namespace App\Modules\Customers\Enums;

enum GiftCardStatusEnum: string
{
    case ACTIVE = 'ACTIVE';
    case DEPLETED = 'DEPLETED';
    case EXPIRED = 'EXPIRED';
    case BLOCKED = 'BLOCKED';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Active',
            self::DEPLETED => 'Depleted',
            self::EXPIRED => 'Expired',
            self::BLOCKED => 'Blocked',
        };
    }
}
