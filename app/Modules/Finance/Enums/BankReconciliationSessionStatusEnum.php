<?php

declare(strict_types=1);

namespace App\Modules\Finance\Enums;

enum BankReconciliationSessionStatusEnum: string
{
    case IN_PROGRESS = 'IN_PROGRESS';
    case COMPLETED = 'COMPLETED';
    case REOPENED = 'REOPENED';
}
