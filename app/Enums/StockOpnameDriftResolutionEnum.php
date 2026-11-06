<?php

declare(strict_types=1);

namespace App\Enums;

enum StockOpnameDriftResolutionEnum: string
{
    case None = 'NONE';
    case ReSnapshot = 'RE_SNAPSHOT';
    case RebaseOverride = 'REBASE_OVERRIDE';

    public function label(): string
    {
        return match ($this) {
            self::None => 'No Resolution (Reject)',
            self::ReSnapshot => 'Recount (Re-Snapshot)',
            self::RebaseOverride => 'Rebase Override (Align with Current Stock)',
        };
    }
}
