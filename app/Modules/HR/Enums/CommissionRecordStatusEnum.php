<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum CommissionRecordStatusEnum: string
{
    case PENDING = 'PENDING';
    case PROCESSED_IN_PAYROLL = 'PROCESSED_IN_PAYROLL';
    case VOIDED = 'VOIDED';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Menunggu Penggajian',
            self::PROCESSED_IN_PAYROLL => 'Telah Diproses Penggajian',
            self::VOIDED => 'Dibatalkan / Void',
        };
    }
}
