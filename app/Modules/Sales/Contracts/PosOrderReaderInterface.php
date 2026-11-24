<?php

declare(strict_types=1);

namespace App\Modules\Sales\Contracts;

/**
 * Read-only contract for POS Order facts accessed by Financial Outbox and downstream consumers (ADR-111).
 */
interface PosOrderReaderInterface
{
    public function getId(): int;

    public function getTenantId(): int;

    public function getStoreId(): ?int;

    public function getOrderNumber(): string;

    public function getTotalAmount(): float|string;

    public function getTaxAmount(): float|string;

    public function getDiscountAmount(): float|string;

    public function getSubtotal(): float|string;

    public function getServiceChargeAmount(): float|string;

    public function getRoundingAdjustment(): float|string;

    public function getCreatedAt(): mixed;
}
