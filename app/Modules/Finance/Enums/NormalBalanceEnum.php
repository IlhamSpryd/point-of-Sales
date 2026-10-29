<?php

declare(strict_types=1);

namespace App\Modules\Finance\Enums;

enum NormalBalanceEnum: string
{
    case DEBIT = 'DEBIT';
    case CREDIT = 'CREDIT';
}
