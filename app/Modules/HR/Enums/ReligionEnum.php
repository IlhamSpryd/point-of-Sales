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
            self::KRISTEN => 'Protestantism',
            self::KATOLIK => 'Catholicism',
            self::HINDU => 'Hinduism',
            self::BUDDHA => 'Buddhism',
            self::KONGHUCU => 'Confucianism',
            self::OTHER => 'Other',
        };
    }
}
