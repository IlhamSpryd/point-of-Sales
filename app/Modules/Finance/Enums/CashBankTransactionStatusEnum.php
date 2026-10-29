<?php

declare(strict_types=1);

namespace App\Modules\Finance\Enums;

enum CashBankTransactionStatusEnum: string
{
    case DRAFT = 'DRAFT';
    case POSTED = 'POSTED';
    case REVERSED = 'REVERSED';
}
