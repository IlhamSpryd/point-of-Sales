<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Color\StatusPalette;

enum PoReceivingStatusEnum: string
{
    case NotReceived = 'not_received';
    case PartiallyReceived = 'partially_received';
    case FullyReceived = 'fully_received';
    case OverReceived = 'over_received';

    public function label(): string
    {
        return match ($this) {
            self::NotReceived => 'Not Received',
            self::PartiallyReceived => 'Partially Received',
            self::FullyReceived => 'Fully Received',
            self::OverReceived => 'Over Received',
        };
    }

    /**
     * Semantic variant for x-badge / x-ui.badge.
     *
     * Resolved centrally so this status renders identically everywhere.
     * Replaces the former badgeClass(), which hardcoded a raw Tailwind palette
     * (and 8 dark: utilities) that could not follow the theme.
     */
    public function badgeType(): string
    {
        return StatusPalette::variant($this)->value;
    }
}
