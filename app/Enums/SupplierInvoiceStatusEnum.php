<?php

declare(strict_types=1);

namespace App\Enums;

enum SupplierInvoiceStatusEnum: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case VarianceHold = 'variance_hold';
    case ApprovedPayable = 'approved_payable';
    case Disputed = 'disputed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::VarianceHold => 'Variance Hold',
            self::ApprovedPayable => 'Approved for Payment',
            self::Disputed => 'Disputed',
            self::Cancelled => 'Cancelled',
        };
    }
}
