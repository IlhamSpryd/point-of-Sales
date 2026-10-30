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
            self::DRAFT => 'Konsep (Draft)',
            self::CALCULATING => 'Sedang Dihitung',
            self::CALCULATED => 'Selesai Dihitung',
            self::PENDING_APPROVAL => 'Menunggu Persetujuan',
            self::APPROVED => 'Disetujui',
            self::FINALIZED => 'Terkunci & Final (Finalized)',
            self::PAYMENT_PENDING => 'Menunggu Pembayaran',
            self::PAID => 'Dibayarkan (Paid)',
            self::VOIDED => 'Dibatalkan (Voided)',
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
