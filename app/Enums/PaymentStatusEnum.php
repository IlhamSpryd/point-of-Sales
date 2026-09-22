<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentStatusEnum: string
{
    case Pending = 'pending';
    case Captured = 'captured';
    case Failed = 'failed';
    case Refunded = 'refunded';

    /** Label ramah UI untuk status per-leg pembayaran di ledger `payments`. */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Menunggu',
            self::Captured => 'Berhasil',
            self::Failed => 'Gagal',
            self::Refunded => 'Dikembalikan',
        };
    }
}
