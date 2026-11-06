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
            self::ACTIVE => 'Active',
            self::ON_PROBATION => 'On Probation',
            self::SUSPENDED => 'Suspended',
            self::RESIGNED => 'Resigned',
            self::TERMINATED => 'Terminated',
            self::ARCHIVED => 'Archived',
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
