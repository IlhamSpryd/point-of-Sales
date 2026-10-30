<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum PayrollAdjustmentTypeEnum: string
{
    case ADDITION = 'ADDITION';
    case DEDUCTION = 'DEDUCTION';

    public function label(): string
    {
        return match ($this) {
            self::ADDITION => 'Penambahan Upah',
            self::DEDUCTION => 'Pemotongan Upah',
        };
    }
}
