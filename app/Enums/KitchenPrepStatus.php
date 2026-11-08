<?php

declare(strict_types=1);

namespace App\Enums;

enum KitchenPrepStatus: string
{
    case NotApplicable = 'not_applicable';
    case Queued = 'queued';
    case Preparing = 'preparing';
    case Ready = 'ready_in_kitchen';
    case Served = 'served_to_table';

    public function label(): string
    {
        return match ($this) {
            self::NotApplicable => 'N/A',
            self::Queued => 'Queued',
            self::Preparing => 'Preparing',
            self::Ready => 'Ready in Kitchen',
            self::Served => 'Served',
        };
    }
}
