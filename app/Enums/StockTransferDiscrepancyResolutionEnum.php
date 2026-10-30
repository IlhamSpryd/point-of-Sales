<?php

declare(strict_types=1);

namespace App\Enums;

enum StockTransferDiscrepancyResolutionEnum: string
{
    case None = 'NONE';
    case ReturnToSource = 'RETURN_TO_SOURCE';
    case WriteOffInTransit = 'WRITE_OFF_IN_TRANSIT';

    public function label(): string
    {
        return match ($this) {
            self::None => 'No Discrepancy',
            self::ReturnToSource => 'Return Discrepancy to Source',
            self::WriteOffInTransit => 'Write-off In-Transit Shrinkage',
        };
    }
}
