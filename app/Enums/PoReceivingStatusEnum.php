<?php

declare(strict_types=1);

namespace App\Enums;

enum PoReceivingStatusEnum: string
{
    case NotReceived = 'not_received';
    case PartiallyReceived = 'partially_received';
    case FullyReceived = 'fully_received';
    case OverReceived = 'over_received';

    public function label(): string
    {
        return match ($this) {
            self::NotReceived => 'Belum Diterima',
            self::PartiallyReceived => 'Diterima Sebagian',
            self::FullyReceived => 'Diterima Penuh',
            self::OverReceived => 'Kelebihan Terima',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::NotReceived => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300',
            self::PartiallyReceived => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
            self::FullyReceived => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300',
            self::OverReceived => 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-300',
        };
    }
}
