<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum CommissionBasisTypeEnum: string
{
    case PERCENTAGE_OF_SALES = 'PERCENTAGE_OF_SALES';
    case FIXED_PER_TRANSACTION = 'FIXED_PER_TRANSACTION';
    case PRODUCT_SPECIFIC = 'PRODUCT_SPECIFIC';
    case TIERED_TARGET = 'TIERED_TARGET';
    case SHARED_POOL = 'SHARED_POOL';

    public function label(): string
    {
        return match ($this) {
            self::PERCENTAGE_OF_SALES => 'Percentage of Net Sales',
            self::FIXED_PER_TRANSACTION => 'Fixed Amount Per Transaction',
            self::PRODUCT_SPECIFIC => 'Product-Specific Incentive',
            self::TIERED_TARGET => 'Tiered Target Commission',
            self::SHARED_POOL => 'Shared Team Commission Pool',
        };
    }
}
