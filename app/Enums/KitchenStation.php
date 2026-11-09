<?php

declare(strict_types=1);

namespace App\Enums;

enum KitchenStation: string
{
    case Kitchen = 'kitchen';
    case Bar = 'bar';
    case Dessert = 'dessert';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Kitchen => 'Kitchen / Dapur',
            self::Bar => 'Bar / Minuman',
            self::Dessert => 'Dessert / Pastry',
            self::None => 'Tanpa Stasiun',
        };
    }
}
