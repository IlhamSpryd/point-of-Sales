<?php

declare(strict_types=1);

namespace App\Modules\Finance\Enums;

enum DepreciationMethodEnum: string
{
    case STRAIGHT_LINE = 'STRAIGHT_LINE';
    case DECLINING_BALANCE = 'DECLINING_BALANCE';
}
