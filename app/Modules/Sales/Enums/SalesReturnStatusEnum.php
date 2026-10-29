<?php

declare(strict_types=1);

namespace App\Modules\Sales\Enums;

enum SalesReturnStatusEnum: string
{
    case PENDING_APPROVAL = 'pending_approval';
    case APPROVED = 'approved';
    case COMPLETED = 'completed';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PENDING_APPROVAL => 'Menunggu Persetujuan',
            self::APPROVED => 'Disetujui',
            self::COMPLETED => 'Selesai',
            self::REJECTED => 'Ditolak',
            self::CANCELLED => 'Dibatalkan',
        };
    }
}
