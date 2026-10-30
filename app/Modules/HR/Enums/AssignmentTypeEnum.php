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
            self::PRIMARY => 'Cabang Utama (Primary)',
            self::SECONDARY => 'Cabang Sekunder (Floating/Secondary)',
            self::TEMPORARY => 'Penugasan Sementara (Temporary)',
        };
    }
}
