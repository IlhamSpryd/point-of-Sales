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
            self::SCHEDULED => 'Terjadwal (Scheduled)',
            self::SWAPPED => 'Ditukar (Swapped)',
            self::CANCELLED => 'Dibatalkan (Cancelled)',
            self::COMPLETED => 'Selesai Dijalani (Completed)',
        };
    }
}
