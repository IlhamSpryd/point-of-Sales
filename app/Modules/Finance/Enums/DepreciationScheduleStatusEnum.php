<?php

declare(strict_types=1);

namespace App\Modules\Finance\Enums;

enum DepreciationScheduleStatusEnum: string
{
    case PENDING = 'PENDING';
    case POSTED = 'POSTED';
}
