<?php

declare(strict_types=1);

namespace App\Enums;

enum GrnStatusEnum: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Reversed = 'reversed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draf',
            self::Posted => 'Diposting',
            self::Reversed => 'Dibalik',
            self::Cancelled => 'Dibatalkan',
        };
    }
}
