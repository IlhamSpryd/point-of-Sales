<?php

declare(strict_types=1);

namespace App\Modules\Customers\Enums;

enum VoucherStatusEnum: string
{
    case ACTIVE = 'ACTIVE';
    case EXPIRED = 'EXPIRED';
    case DISABLED = 'DISABLED';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Aktif',
            self::EXPIRED => 'Kedaluwarsa',
            self::DISABLED => 'Dinonaktifkan',
        };
    }
}
