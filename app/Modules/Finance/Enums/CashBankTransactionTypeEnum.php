<?php

declare(strict_types=1);

namespace App\Modules\Finance\Enums;

enum CashBankTransactionTypeEnum: string
{
    case RECEIPT = 'RECEIPT';
    case DISBURSEMENT = 'DISBURSEMENT';
    case TRANSFER_OUT = 'TRANSFER_OUT';
    case TRANSFER_IN = 'TRANSFER_IN';
    case POS_SHIFT_DEPOSIT = 'POS_SHIFT_DEPOSIT';
    case PG_SETTLEMENT = 'PG_SETTLEMENT';
    case BANK_FEE = 'BANK_FEE';
    case BANK_INTEREST = 'BANK_INTEREST';
}
