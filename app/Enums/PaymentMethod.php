<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * [D2-BE-006] Enum metode pembayaran OTORITATIF.
 *
 * Dahulu ada dua enum identik (PaymentMethod & PaymentMethodEnum).
 * PaymentMethodEnum kini thin alias @deprecated dari enum ini. Nilai-nilai
 * selaras 100% dengan CHECK constraint `orders.payment_method` /
 * `payments.payment_method` ('cash','qris','ewallet','card').
 */
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

    /** @return list<string> Nilai yang sah untuk CHECK constraint di DB. */
    public static function allowedValues(): array
    {
        return array_column(self::cases(), 'value');
    }
}
