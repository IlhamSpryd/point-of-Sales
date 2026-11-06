<?php

declare(strict_types=1);

namespace App\Enums;

enum PurchaseOrderSourceTypeEnum: string
{
    case FromPr = 'from_pr';
    case Direct = 'direct';

    public function label(): string
    {
        return match ($this) {
            self::FromPr => 'Purchase Request (PR)',
            self::Direct => 'Direct PO',
        };
    }
}
