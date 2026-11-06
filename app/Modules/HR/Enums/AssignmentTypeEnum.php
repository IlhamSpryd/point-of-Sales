<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum AssignmentTypeEnum: string
{
    case PRIMARY = 'PRIMARY';
    case SECONDARY = 'SECONDARY';
    case TEMPORARY = 'TEMPORARY';

    public function label(): string
    {
        return match ($this) {
            self::PRIMARY => 'Primary Branch',
            self::SECONDARY => 'Secondary Branch',
            self::TEMPORARY => 'Temporary Assignment',
        };
    }
}
