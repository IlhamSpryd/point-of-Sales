<?php

declare(strict_types=1);

namespace App\Modules\Finance\Enums;

enum BankReconciliationMatchTypeEnum: string
{
    case EXACT_AUTO = 'EXACT_AUTO';
    case MANUAL_1_TO_1 = 'MANUAL_1_TO_1';
    case MANUAL_SPLIT = 'MANUAL_SPLIT';
    case ADJUSTMENT_ENTRY = 'ADJUSTMENT_ENTRY';
}
