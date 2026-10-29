<?php

declare(strict_types=1);

namespace App\Modules\Finance\Enums;

enum TaxDirectionEnum: string
{
    case OUTPUT_TAX = 'OUTPUT_TAX';
    case INPUT_TAX = 'INPUT_TAX';
}
