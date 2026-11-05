<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Color\StatusPalette;

enum PurchaseOrderStatusEnum: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Released = 'released';
    case Amended = 'amended';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingApproval => 'Pending Approval',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Released => 'Released',
            self::Amended => 'Amended',
            self::Closed => 'Closed',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Semantic variant for x-badge / x-ui.badge.
     *
     * Resolved centrally so this status renders identically everywhere.
     * Replaces the former badgeClass(), which hardcoded a raw Tailwind palette
     * (and 8 dark: utilities) and disagreed with every other status authority:
     * it painted `Approved` blue while the rest of the app treated approval as success.
     */
    public function badgeType(): string
    {
        return match ($this) {
            self::Draft => StatusPalette::variant('draft')->value,
            self::PendingApproval => StatusPalette::variant('pending_approval')->value,
            self::Approved => StatusPalette::variant('approved')->value,
            self::Rejected => StatusPalette::variant('rejected')->value,
            self::Released => StatusPalette::variant('released')->value,
            self::Amended => StatusPalette::variant('amended')->value,
            self::Closed => StatusPalette::variant('closed')->value,
            self::Cancelled => StatusPalette::variant('cancelled')->value,
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Closed, self::Cancelled, self::Rejected], true);
    }
}
