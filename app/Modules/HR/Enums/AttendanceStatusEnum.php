<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum AttendanceStatusEnum: string
{
    case PRESENT = 'PRESENT';
    case LATE = 'LATE';
    case EARLY_LEAVE = 'EARLY_LEAVE';
    case ABSENT = 'ABSENT';
    case ON_LEAVE = 'ON_LEAVE';
    case SICK = 'SICK';
    case DAY_OFF = 'DAY_OFF';
    case CORRECTED = 'CORRECTED';

    public function label(): string
    {
        return match ($this) {
            self::PRESENT => 'Present',
            self::LATE => 'Late',
            self::EARLY_LEAVE => 'Early Departure',
            self::ABSENT => 'Absent',
            self::ON_LEAVE => 'On Leave',
            self::SICK => 'Sick Leave',
            self::DAY_OFF => 'Day Off',
            self::CORRECTED => 'Corrected',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::PRESENT => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            self::LATE, self::EARLY_LEAVE => 'bg-amber-50 text-amber-700 border-amber-200',
            self::CORRECTED => 'bg-blue-50 text-blue-700 border-blue-200',
            self::ABSENT, self::SICK => 'bg-rose-50 text-rose-700 border-rose-200',
            self::ON_LEAVE, self::DAY_OFF => 'bg-gray-100 text-gray-700 border-gray-200',
        };
    }
}
