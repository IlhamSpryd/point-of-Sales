<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Color\StatusPalette;

enum PoInvoicingStatusEnum: string
{
    case NotInvoiced = 'not_invoiced';
    case PartiallyInvoiced = 'partially_invoiced';
    case FullyInvoiced = 'fully_invoiced';

    public function label(): string
    {
        return match ($this) {
            self::NotInvoiced => 'Belum Ditagih',
            self::PartiallyInvoiced => 'Ditagih Sebagian',
            self::FullyInvoiced => 'Ditagih Penuh',
        };
    }

    /**
     * Semantic variant for x-badge / x-ui.badge.
     *
     * Resolved centrally so this status renders identically everywhere.
     * Replaces the former badgeClass(), which hardcoded a raw Tailwind palette
     * (and 4 dark: utilities) that could not follow the theme.
     */
    public function badgeType(): string
    {
        return match ($this) {
            self::NotInvoiced => StatusPalette::variant('draft')->value,
            self::PartiallyInvoiced => StatusPalette::variant('partially_paid')->value,
            self::FullyInvoiced => StatusPalette::variant('paid')->value,
        };
    }
}
