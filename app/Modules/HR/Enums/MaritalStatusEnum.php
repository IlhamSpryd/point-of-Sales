<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum MaritalStatusEnum: string
{
    case SINGLE = 'SINGLE';
    case MARRIED = 'MARRIED';
    case WIDOWED = 'WIDOWED';
    case DIVORCED = 'DIVORCED';

    public function label(): string
    {
        return match ($this) {
            self::SINGLE => 'Belum Menikah',
            self::MARRIED => 'Menikah',
            self::WIDOWED => 'Janda/Duda (Meninggal)',
            self::DIVORCED => 'Cerai Hidup',
        };
    }
}
