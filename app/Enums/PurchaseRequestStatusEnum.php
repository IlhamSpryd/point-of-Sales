<?php

declare(strict_types=1);

namespace App\Enums;

enum PurchaseRequestStatusEnum: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function isDraft(): bool
    {
        return $this === self::Draft;
    }

    public function isSubmitted(): bool
    {
        return $this === self::Submitted;
    }

    public function isApproved(): bool
    {
        return $this === self::Approved;
    }

    public function isImmutable(): bool
    {
        return $this !== self::Draft;
    }

    public function canBeCancelled(): bool
    {
        return in_array($this, [self::Draft, self::Submitted], true);
    }

    public function canBeConvertedToPo(): bool
    {
        return $this === self::Approved;
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draf',
            self::Submitted => 'Menunggu Persetujuan',
            self::Approved => 'Disetujui',
            self::Rejected => 'Ditolak',
            self::Closed => 'Ditutup',
            self::Cancelled => 'Dibatalkan',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Draft => 'neutral',
            self::Submitted => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
            self::Closed => 'secondary',
            self::Cancelled => 'danger',
        };
    }
}
