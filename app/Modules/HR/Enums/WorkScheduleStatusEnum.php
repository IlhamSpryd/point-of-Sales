<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum WorkScheduleStatusEnum: string
{
    case SCHEDULED = 'SCHEDULED';
    case SWAPPED = 'SWAPPED';
    case CANCELLED = 'CANCELLED';
    case COMPLETED = 'COMPLETED';

    public function label(): string
    {
        return match ($this) {
            self::SCHEDULED => 'Scheduled',
            self::SWAPPED => 'Swapped',
            self::CANCELLED => 'Cancelled',
            self::COMPLETED => 'Completed',
        };
    }
}
