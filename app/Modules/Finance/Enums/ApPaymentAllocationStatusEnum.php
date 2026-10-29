<?php

declare(strict_types=1);

namespace App\Modules\Finance\Enums;

enum ApPaymentAllocationStatusEnum: string
{
    case POSTED = 'POSTED';
    case REVERSED = 'REVERSED';
}
