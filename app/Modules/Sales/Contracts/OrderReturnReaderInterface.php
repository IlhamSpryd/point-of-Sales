<?php

declare(strict_types=1);

namespace App\Modules\Sales\Contracts;

/**
 * Read-only contract for Order Return facts accessed by Financial Outbox (ADR-111).
 */
interface OrderReturnReaderInterface
{
    public function getId(): int;

    public function getTenantId(): int;

    public function getStoreId(): ?int;

    public function getOrderId(): ?int;

    public function getReturnNumber(): string;

    public function getRefundAmount(): float|string;

    public function getSubtotalReturned(): float|string;

    public function getTaxReturned(): float|string;

    public function getCreatedAt(): mixed;

    public function getCompletedAt(): mixed;
}
