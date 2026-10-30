<?php

declare(strict_types=1);

namespace App\Enums;

enum InvoiceMatchingStatusEnum: string
{
    case Unmatched = 'unmatched';
    case PartiallyMatched = 'partially_matched';
    case Matched = 'matched';
    case VarianceDetected = 'variance_detected';

    public function label(): string
    {
        return match ($this) {
            self::Unmatched => 'Unmatched',
            self::PartiallyMatched => 'Partially Matched',
            self::Matched => 'Fully Matched',
            self::VarianceDetected => 'Variance Detected',
        };
    }
}
