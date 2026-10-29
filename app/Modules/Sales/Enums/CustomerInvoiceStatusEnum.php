<?php

declare(strict_types=1);

namespace App\Modules\Sales\Enums;

enum CustomerInvoiceStatusEnum: string
{
    case DRAFT = 'DRAFT';
    case POSTED = 'POSTED';
    case PARTIALLY_PAID = 'PARTIALLY_PAID';
    case PAID = 'PAID';
    case VOIDED = 'VOIDED';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Konsep (Draft)',
            self::POSTED => 'Terbit (Posted)',
            self::PARTIALLY_PAID => 'Dibayar Sebagian (Partially Paid)',
            self::PAID => 'Lunas (Paid)',
            self::VOIDED => 'Dibatalkan (Voided)',
        };
    }

    public function canAllocatePayment(): bool
    {
        return in_array($this, [self::POSTED, self::PARTIALLY_PAID], true);
    }

    public function isImmutable(): bool
    {
        return in_array($this, [self::POSTED, self::PARTIALLY_PAID, self::PAID, self::VOIDED], true);
    }
}
