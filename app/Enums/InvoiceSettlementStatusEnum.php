<?php

declare(strict_types=1);

namespace App\Enums;

enum InvoiceSettlementStatusEnum: string
{
    case Unpaid = 'unpaid';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Belum Lunas',
            self::PartiallyPaid => 'Dibayar Sebagian',
            self::Paid => 'Lunas',
        };
    }
}
