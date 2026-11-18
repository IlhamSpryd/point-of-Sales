<?php

declare(strict_types=1);

namespace App\Modules\Sales\Contracts;

/**
 * Read-only contract for Cashier Shift facts accessed by Financial Outbox (ADR-111).
 */
interface ShiftReaderInterface
{
    public function getId(): int;

    public function getTenantId(): int;

    public function getStoreId(): ?int;

    public function getUserId(): int;

    public function getShiftNumber(): ?string;

    public function getCashDifference(): float|string;

    public function getExpectedCash(): float|string;

    public function getClosingBalance(): float|string;

    public function getClosedAt(): mixed;
}
