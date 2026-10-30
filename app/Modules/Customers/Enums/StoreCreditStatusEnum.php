<?php

declare(strict_types=1);

namespace App\Modules\Customers\Enums;

enum StoreCreditStatusEnum: string
{
    case ACTIVE = 'ACTIVE';
    case FROZEN = 'FROZEN';
    case CLOSED = 'CLOSED';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Active',
            self::FROZEN => 'Frozen',
            self::CLOSED => 'Closed',
        };
    }
}
