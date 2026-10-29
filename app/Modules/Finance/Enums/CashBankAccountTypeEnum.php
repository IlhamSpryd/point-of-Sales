<?php

declare(strict_types=1);

namespace App\Modules\Finance\Enums;

enum CashBankAccountTypeEnum: string
{
    case PETTY_CASH = 'PETTY_CASH';
    case POS_DRAWER_CLEARING = 'POS_DRAWER_CLEARING';
    case STORE_SAFE = 'STORE_SAFE';
    case BANK_ACCOUNT = 'BANK_ACCOUNT';
    case PAYMENT_GATEWAY_CLEARING = 'PAYMENT_GATEWAY_CLEARING';
}
