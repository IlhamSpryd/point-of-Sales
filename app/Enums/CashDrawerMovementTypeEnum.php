<?php

declare(strict_types=1);

namespace App\Enums;

enum CashDrawerMovementTypeEnum: string
{
    case CashIn = 'cash_in';
    case CashOut = 'cash_out';
}
