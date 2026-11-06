<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum OvertimeDayTypeEnum: string
{
    case WORKDAY = 'WORKDAY';
    case WEEKLY_REST = 'WEEKLY_REST';
    case PUBLIC_HOLIDAY = 'PUBLIC_HOLIDAY';

    public function label(): string
    {
        return match ($this) {
            self::WORKDAY => 'Regular Workday',
            self::WEEKLY_REST => 'Weekly Rest Day',
            self::PUBLIC_HOLIDAY => 'Public Holiday',
        };
    }
}
