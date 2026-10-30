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
            self::Pending => 'Pending Payment',
            self::Paid => 'Paid',
            self::Cancelled => 'Cancelled',
            self::Expired => 'Expired',
            self::Failed => 'Failed',
            self::Completed => 'Completed',
            self::Void => 'Voided',
        };
    }

    /** Variant semantic x-badge untuk status pesanan. */
    public function badgeType(): string
    {
        return match ($this) {
            self::Paid, self::Completed => 'success',
            self::Pending => 'warning',
            self::Void, self::Failed => 'danger',
            self::Cancelled, self::Expired => 'secondary',
        };
    }
}
