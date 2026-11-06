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
            self::SINGLE => 'Single',
            self::MARRIED => 'Married',
            self::WIDOWED => 'Widowed',
            self::DIVORCED => 'Divorced',
        };
    }
}
