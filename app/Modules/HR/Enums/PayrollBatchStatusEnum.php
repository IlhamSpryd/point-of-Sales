<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum PayrollBatchStatusEnum: string
{
    case DRAFT = 'DRAFT';
    case CALCULATING = 'CALCULATING';
    case CALCULATED = 'CALCULATED';
    case PENDING_APPROVAL = 'PENDING_APPROVAL';
    case APPROVED = 'APPROVED';
    case FINALIZED = 'FINALIZED';
    case PAYMENT_PENDING = 'PAYMENT_PENDING';
    case PAID = 'PAID';
    case VOIDED = 'VOIDED';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::CALCULATING => 'Calculating',
            self::CALCULATED => 'Calculated',
            self::PENDING_APPROVAL => 'Pending Approval',
            self::APPROVED => 'Approved',
            self::FINALIZED => 'Finalized',
            self::PAYMENT_PENDING => 'Payment Pending',
            self::PAID => 'Paid',
            self::VOIDED => 'Voided',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::DRAFT => 'bg-muted text-muted-foreground border-border',
            self::CALCULATING => 'bg-amber-50 text-amber-700 border-amber-200',
            self::CALCULATED => 'bg-blue-50 text-blue-700 border-blue-200',
            self::PENDING_APPROVAL => 'bg-amber-50 text-amber-700 border-amber-200',
            self::APPROVED, self::FINALIZED => 'bg-indigo-50 text-indigo-700 border-indigo-200',
            self::PAYMENT_PENDING => 'bg-purple-50 text-purple-700 border-purple-200',
            self::PAID => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            self::VOIDED => 'bg-rose-50 text-rose-700 border-rose-200',
        };
    }

    public function isImmutable(): bool
    {
        return match ($this) {
            self::FINALIZED, self::PAYMENT_PENDING, self::PAID => true,
            default => false,
        };
    }
}
