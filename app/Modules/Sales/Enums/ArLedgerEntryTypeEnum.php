<?php

declare(strict_types=1);

namespace App\Modules\Sales\Enums;

enum ArLedgerEntryTypeEnum: string
{
    case INVOICE_DEBIT = 'INVOICE_DEBIT';
    case PAYMENT_CREDIT = 'PAYMENT_CREDIT';
    case CREDIT_NOTE = 'CREDIT_NOTE';
    case DEBIT_ADJUSTMENT = 'DEBIT_ADJUSTMENT';
    case REVERSAL = 'REVERSAL';

    public function label(): string
    {
        return match ($this) {
            self::INVOICE_DEBIT => 'Invoice Debit',
            self::PAYMENT_CREDIT => 'Payment Credit',
            self::CREDIT_NOTE => 'Credit Note',
            self::DEBIT_ADJUSTMENT => 'Debit Adjustment',
            self::REVERSAL => 'Reversal',
        };
    }

    public function isDebit(): bool
    {
        return in_array($this, [self::INVOICE_DEBIT, self::DEBIT_ADJUSTMENT], true);
    }

    public function isCredit(): bool
    {
        return in_array($this, [self::PAYMENT_CREDIT, self::CREDIT_NOTE, self::REVERSAL], true);
    }
}
