<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Exceptions;

use DomainException;

class InsufficientStockException extends DomainException
{
    public function __construct(
        string $message = 'Stok tidak mencukupi untuk memenuhi transaksi penjualan.',
        public readonly ?string $itemType = null,
        public readonly ?int $itemId = null,
        public readonly float|string|null $available = null,
        public readonly float|string|null $requested = null
    ) {
        parent::__construct($message);
    }
}
