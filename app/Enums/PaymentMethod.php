<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Qris = 'qris';
    case Ewallet = 'ewallet';
    case Card = 'card';

    /** Label ramah untuk UI (jangan pernah membandingkan string mentah di View). */
    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Tunai',
            self::Qris => 'QRIS',
            self::Ewallet => 'E-Wallet',
            self::Card => 'Kartu (Debit/Kredit)',
        };
    }
}
