<?php

declare(strict_types=1);

namespace App\Modules\Finance\Enums;

enum BankStatementLineMatchStatusEnum: string
{
    case UNMATCHED = 'UNMATCHED';
    case MATCHED = 'MATCHED';
    case ADJUSTED = 'ADJUSTED';
    case EXCLUDED = 'EXCLUDED';
}
