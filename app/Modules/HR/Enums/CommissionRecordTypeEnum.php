<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum CommissionRecordTypeEnum: string
{
    case STANDARD = 'STANDARD';
    case REVERSAL = 'REVERSAL';
    case ADJUSTMENT = 'ADJUSTMENT';

    public function label(): string
    {
        return match ($this) {
            self::STANDARD => 'Komisi Standar',
            self::REVERSAL => 'Pembalikan Retur / Batal',
            self::ADJUSTMENT => 'Penyesuaian Manual',
        };
    }
}
