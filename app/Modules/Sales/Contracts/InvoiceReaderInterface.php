<?php

declare(strict_types=1);

namespace App\Modules\Sales\Contracts;

/**
 * Read-only contract for Customer Invoice access across module boundaries.
 * Decouples Finance, Reporting, and other domains from Sales internal models (ADR-111).
 */
interface InvoiceReaderInterface
{
    public function getId(): int;

    public function getTenantId(): int;

    public function getInvoiceNumber(): string;

    public function getTaxInvoiceSerialNumber(): ?string;

    public function setTaxInvoiceSerialNumber(?string $serialNumber): void;

    public function getInvoiceDate(): mixed;

    public function getSubtotal(): float|string;

    public function getTaxAmount(): float|string;

    public function getTotalAmount(): float|string;

    public function getCustomerTaxId(): ?string;

    public function getCustomerName(): ?string;

    public function getCustomerAddress(): ?string;
}
