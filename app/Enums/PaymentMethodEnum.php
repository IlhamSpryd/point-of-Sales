<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentMethodEnum: string
{
    case Cash = 'cash';
    case Qris = 'qris';
    case Ewallet = 'ewallet';
    case Card = 'card';

    /** Label ramah UI, konsisten dengan App\Enums\PaymentMethod::label(). */
    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Tunai',
            self::Qris => 'QRIS',
            self::Ewallet => 'E-Wallet',
            self::Card => 'Kartu',
        };
    }
}
