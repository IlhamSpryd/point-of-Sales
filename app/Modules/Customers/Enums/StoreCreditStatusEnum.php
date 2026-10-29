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
            self::ACTIVE => 'Aktif',
            self::FROZEN => 'Dibekukan',
            self::CLOSED => 'Ditutup',
        };
    }
}
