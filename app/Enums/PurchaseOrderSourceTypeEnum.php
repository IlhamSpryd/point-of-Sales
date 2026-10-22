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
            self::FromPr => 'Dari Permintaan Pembelian (PR)',
            self::Direct => 'Pesanan Langsung (Direct PO)',
        };
    }
}
