<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Enums;

enum CommissionRecordTypeEnum: string
{
    case STANDARD = 'STANDARD';
    case REVERSAL = 'REVERSAL';
    case CLAWBACK = 'CLAWBACK';
    case ADJUSTMENT = 'ADJUSTMENT';

    public function label(): string
    {
        return match ($this) {
            self::STANDARD => 'Standard Commission',
            self::REVERSAL => 'Reversal (Return / Cancel)',
            self::CLAWBACK => 'Clawback Deduction',
            self::ADJUSTMENT => 'Manual Adjustment',
        };
    }
}
