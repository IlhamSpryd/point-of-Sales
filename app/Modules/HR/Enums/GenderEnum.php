<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum GenderEnum: string
{
    case MALE = 'MALE';
    case FEMALE = 'FEMALE';

    public function label(): string
    {
        return match ($this) {
            self::MALE => 'Laki-laki',
            self::FEMALE => 'Perempuan',
        };
    }
}
