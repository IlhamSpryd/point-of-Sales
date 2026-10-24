<?php

declare(strict_types=1);

namespace App\Enums;

enum ApprovalStatusEnum: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Escalated = 'escalated';
    case Cancelled = 'cancelled';

    public function isPending(): bool
    {
        return $this === self::Pending;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Menunggu Keputusan',
            self::Approved => 'Disetujui',
            self::Rejected => 'Ditolak',
            self::Escalated => 'Dieskalasi',
            self::Cancelled => 'Dibatalkan',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
            self::Escalated => 'secondary',
            self::Cancelled => 'neutral',
        };
    }
}
