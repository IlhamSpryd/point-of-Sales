<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum LeaveTransactionTypeEnum: string
{
    case ACCRUAL = 'ACCRUAL';
    case USAGE = 'USAGE';
    case REVERSAL = 'REVERSAL';
    case ADJUSTMENT = 'ADJUSTMENT';
    case EXPIRATION = 'EXPIRATION';

    public function label(): string
    {
        return match ($this) {
            self::ACCRUAL => 'Accrual',
            self::USAGE => 'Usage',
            self::REVERSAL => 'Reversal',
            self::ADJUSTMENT => 'Manual Adjustment',
            self::EXPIRATION => 'Expiration',
        };
    }
}
