<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Failed = 'failed';
    case Completed = 'completed';
    case Void = 'void';

    /** Apakah status ini dianggap "final" (tidak bisa berubah lagi). */
    public function isFinal(): bool
    {
        return match ($this) {
            self::Paid, self::Cancelled, self::Expired, self::Failed, self::Completed, self::Void => true,
            default => false,
        };
    }

    /** Label yang ramah untuk ditampilkan ke UI. */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Menunggu Pembayaran',
            self::Paid => 'Lunas',
            self::Cancelled => 'Dibatalkan',
            self::Expired => 'Kedaluwarsa',
            self::Failed => 'Gagal',
            self::Completed => 'Selesai',
            self::Void => 'Di-void',
        };
    }
}
