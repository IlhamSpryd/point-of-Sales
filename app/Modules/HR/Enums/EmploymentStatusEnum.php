<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum EmploymentStatusEnum: string
{
    case DRAFT = 'DRAFT';
    case ACTIVE = 'ACTIVE';
    case ON_PROBATION = 'ON_PROBATION';
    case SUSPENDED = 'SUSPENDED';
    case RESIGNED = 'RESIGNED';
    case TERMINATED = 'TERMINATED';
    case ARCHIVED = 'ARCHIVED';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::ACTIVE => 'Aktif',
            self::ON_PROBATION => 'Masa Percobaan',
            self::SUSPENDED => 'Ditangguhkan',
            self::RESIGNED => 'Mengundurkan Diri',
            self::TERMINATED => 'Diberhentikan',
            self::ARCHIVED => 'Diarsipkan',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::DRAFT => 'secondary',
            self::ACTIVE => 'success',
            self::ON_PROBATION => 'warning',
            self::SUSPENDED => 'danger',
            self::RESIGNED => 'warning',
            self::TERMINATED => 'danger',
            self::ARCHIVED => 'neutral',
        };
    }
}
