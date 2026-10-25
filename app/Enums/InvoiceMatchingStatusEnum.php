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
            self::Unmatched => 'Belum Dicocokkan',
            self::PartiallyMatched => 'Cocok Sebagian',
            self::Matched => 'Cocok Penuh (Matched)',
            self::VarianceDetected => 'Terdeteksi Varians',
        };
    }
}
