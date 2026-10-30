<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum OvertimeStatusEnum: string
{
    case PENDING = 'PENDING';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
    case PROCESSED_IN_PAYROLL = 'PROCESSED_IN_PAYROLL';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Menunggu Persetujuan',
            self::APPROVED => 'Disetujui',
            self::REJECTED => 'Ditolak',
            self::PROCESSED_IN_PAYROLL => 'Diproses dalam Payroll',
        };
    }
}
