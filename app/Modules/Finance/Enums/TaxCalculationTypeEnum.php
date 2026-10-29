<?php

declare(strict_types=1);

namespace App\Modules\Finance\Enums;

enum TaxCalculationTypeEnum: string
{
    case EXCLUSIVE = 'EXCLUSIVE';
    case INCLUSIVE = 'INCLUSIVE';
}
