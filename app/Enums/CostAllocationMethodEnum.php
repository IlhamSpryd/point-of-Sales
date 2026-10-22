<?php

declare(strict_types=1);

namespace App\Enums;

enum CostAllocationMethodEnum: string
{
    case ByValue = 'BY_VALUE';
    case ByQuantity = 'BY_QUANTITY';
    case ManualExact = 'MANUAL_EXACT';

    public function label(): string
    {
        return match ($this) {
            self::ByValue => 'Proporsional Nilai Bersih (By Value)',
            self::ByQuantity => 'Proporsional Kuantitas Fisik (By Quantity)',
            self::ManualExact => 'Nominal Manual Eksak (Manual Exact)',
        };
    }
}
