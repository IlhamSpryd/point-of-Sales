<?php

declare(strict_types=1);

namespace App\Enums;

enum StockOpnameStatusEnum: string
{
    case Draft = 'DRAFT';
    case Counting = 'COUNTING';
    case PendingApproval = 'PENDING_APPROVAL';
    case Approved = 'APPROVED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Counting => 'Sedang Dihitung (Counting)',
            self::PendingApproval => 'Menunggu Persetujuan (Pending Approval)',
            self::Approved => 'Disetujui & Disesuaikan (Approved)',
            self::Cancelled => 'Dibatalkan (Cancelled)',
        };
    }

    public function badgeType(): string
    {
        return match ($this) {
            self::Draft => 'secondary',
            self::Counting => 'warning',
            self::PendingApproval => 'info',
            self::Approved => 'success',
            self::Cancelled => 'danger',
        };
    }

    public function canStartCounting(): bool
    {
        return $this === self::Draft;
    }

    public function canRecordCount(): bool
    {
        return $this === self::Counting;
    }

    public function canSubmit(): bool
    {
        return $this === self::Counting;
    }

    public function canApprove(): bool
    {
        return $this === self::PendingApproval;
    }

    public function canCancel(): bool
    {
        return in_array($this, [self::Draft, self::Counting, self::PendingApproval], true);
    }
}
