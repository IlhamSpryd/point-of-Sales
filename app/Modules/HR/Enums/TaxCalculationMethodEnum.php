<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum TaxCalculationMethodEnum: string
{
    case GROSS = 'GROSS';
    case NET = 'NET';
    case GROSS_UP = 'GROSS_UP';

    public function label(): string
    {
        return match ($this) {
            self::GROSS => 'Gross (Employee Borne)',
            self::NET => 'Net (Company Borne)',
            self::GROSS_UP => 'Gross-Up (Tax Allowance)',
        };
    }
}
