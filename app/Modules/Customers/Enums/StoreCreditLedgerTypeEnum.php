<?php

declare(strict_types=1);

namespace App\Modules\Customers\Enums;

enum StoreCreditLedgerTypeEnum: string
{
    case CREDIT_NOTE_ISSUE = 'CREDIT_NOTE_ISSUE';
    case OVERPAYMENT_ISSUE = 'OVERPAYMENT_ISSUE';
    case REDEEM_ORDER = 'REDEEM_ORDER';
    case REDEEM_INVOICE = 'REDEEM_INVOICE';
    case REVERSAL = 'REVERSAL';
    case EXPIRE = 'EXPIRE';

    public function label(): string
    {
        return match ($this) {
            self::CREDIT_NOTE_ISSUE => 'Credit Note Issuance (Return)',
            self::OVERPAYMENT_ISSUE => 'Invoice Overpayment',
            self::REDEEM_ORDER => 'POS / Store Order Payment',
            self::REDEEM_INVOICE => 'AR Invoice Settlement',
            self::REVERSAL => 'Transaction Cancellation Reversal',
            self::EXPIRE => 'Balance Expiration',
        };
    }
}
