<?php

declare(strict_types=1);

namespace App\Modules\Customers\Enums;

enum VoucherDiscountTypeEnum: string
{
    case PERCENTAGE = 'PERCENTAGE';
    case FIXED_AMOUNT = 'FIXED_AMOUNT';

    public function label(): string
    {
        return match ($this) {
            self::PERCENTAGE => 'Percentage (%)',
            self::FIXED_AMOUNT => 'Fixed Amount (Rp)',
        };
    }
}
