<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum CommissionRecordStatusEnum: string
{
    case PENDING = 'PENDING';
    case APPROVED = 'APPROVED';
    case EARNED = 'EARNED';
    case PROCESSED_IN_PAYROLL = 'PROCESSED_IN_PAYROLL';
    case PAID = 'PAID';
    case REVERSED = 'REVERSED';
    case VOIDED = 'VOIDED';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending Review',
            self::APPROVED => 'Approved',
            self::EARNED => 'Earned',
            self::PROCESSED_IN_PAYROLL => 'Processed in Payroll',
            self::PAID => 'Paid Out',
            self::REVERSED => 'Reversed',
            self::VOIDED => 'Voided',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::PENDING => 'bg-amber-50 text-amber-700 border-amber-200',
            self::APPROVED, self::EARNED => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            self::PROCESSED_IN_PAYROLL, self::PAID => 'bg-blue-50 text-blue-700 border-blue-200',
            self::REVERSED => 'bg-rose-50 text-rose-700 border-rose-200',
            self::VOIDED => 'bg-gray-100 text-gray-700 border-gray-200',
        };
    }
}
