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
            self::PRESENT => 'Hadir Tepat Waktu (Present)',
            self::LATE => 'Terlambat (Late)',
            self::EARLY_LEAVE => 'Pulang Lebih Awal (Early Leave)',
            self::ABSENT => 'Mangkir / Tidak Hadir (Absent)',
            self::ON_LEAVE => 'Cuti Resmi (On Leave)',
            self::SICK => 'Sakit (Sick)',
            self::DAY_OFF => 'Hari Libur (Day Off)',
            self::CORRECTED => 'Terkoreksi (Corrected)',
        };
    }
}
