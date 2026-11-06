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
            self::PENDING => 'Pending Approval',
            self::APPROVED => 'Approved',
            self::REJECTED => 'Rejected',
            self::PROCESSED_IN_PAYROLL => 'Processed in Payroll',
        };
    }
}
