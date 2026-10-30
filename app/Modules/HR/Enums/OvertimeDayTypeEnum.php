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
            self::WORKDAY => 'Hari Kerja Biasa',
            self::WEEKLY_REST => 'Hari Istirahat Mingguan',
            self::PUBLIC_HOLIDAY => 'Hari Libur Resmi Nasional',
        };
    }
}
