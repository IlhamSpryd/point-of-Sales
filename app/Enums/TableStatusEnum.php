<?php

declare(strict_types=1);

namespace App\Enums;

enum TableStatusEnum: string
{
    case Available = 'available';
    case Occupied = 'occupied';
    case Cleaning = 'cleaning';
    case Reserved = 'reserved';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Tersedia',
            self::Occupied => 'Terisi',
            self::Cleaning => 'Dibersihkan',
            self::Reserved => 'Dipesan',
            self::Disabled => 'Nonaktif',
        };
    }

    public function badgeType(): string
    {
        return match ($this) {
            self::Available => 'success',
            self::Occupied => 'danger',
            self::Cleaning => 'warning',
            self::Reserved => 'info',
            self::Disabled => 'secondary',
        };
    }

    public function isAvailable(): bool
    {
        return $this === self::Available;
    }

    public function isOccupied(): bool
    {
        return $this === self::Occupied;
    }

    public function isOrderable(): bool
    {
        return $this === self::Available;
    }
}
