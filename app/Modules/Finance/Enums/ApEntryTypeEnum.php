<?php

declare(strict_types=1);

namespace App\Modules\Finance\Enums;

enum ApEntryTypeEnum: string
{
    case INVOICE_CREDIT = 'INVOICE_CREDIT';
    case PAYMENT_DEBIT = 'PAYMENT_DEBIT';
    case DEBIT_NOTE_APPLIED = 'DEBIT_NOTE_APPLIED';
    case CREDIT_ADJUSTMENT = 'CREDIT_ADJUSTMENT';
    case REVERSAL = 'REVERSAL';
}
