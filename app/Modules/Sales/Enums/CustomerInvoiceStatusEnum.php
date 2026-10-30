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
            self::DRAFT => 'Draft',
            self::POSTED => 'Posted',
            self::PARTIALLY_PAID => 'Partially Paid',
            self::PAID => 'Paid',
            self::VOIDED => 'Voided',
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
