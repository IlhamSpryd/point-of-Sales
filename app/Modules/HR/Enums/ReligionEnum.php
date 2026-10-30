<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum ReligionEnum: string
{
    case ISLAM = 'ISLAM';
    case KRISTEN = 'KRISTEN';
    case KATOLIK = 'KATOLIK';
    case HINDU = 'HINDU';
    case BUDDHA = 'BUDDHA';
    case KONGHUCU = 'KONGHUCU';
    case OTHER = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::ISLAM => 'Islam',
            self::KRISTEN => 'Kristen Protestan',
            self::KATOLIK => 'Katolik',
            self::HINDU => 'Hindu',
            self::BUDDHA => 'Buddha',
            self::KONGHUCU => 'Konghucu',
            self::OTHER => 'Lainnya',
        };
    }
}
