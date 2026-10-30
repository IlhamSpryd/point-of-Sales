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
            self::ACCRUAL => 'Penambahan Hak Cuti (Accrual)',
            self::USAGE => 'Pemakaian Cuti (Usage)',
            self::REVERSAL => 'Pengembalian / Pembatalan Cuti (Reversal)',
            self::ADJUSTMENT => 'Penyesuaian Manual (Adjustment)',
            self::EXPIRATION => 'Kedaluwarsa / Hangus (Expiration)',
        };
    }
}
