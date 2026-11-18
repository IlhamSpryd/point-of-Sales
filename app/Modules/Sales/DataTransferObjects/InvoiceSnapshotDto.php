<?php

declare(strict_types=1);

namespace App\Modules\Sales\DataTransferObjects;

use App\Modules\Sales\Contracts\InvoiceReaderInterface;

final readonly class InvoiceSnapshotDto implements InvoiceReaderInterface
{
    public function __construct(
        public int $id,
        public int $tenantId,
        public string $invoiceNumber,
        public ?string $taxInvoiceSerialNumber,
        public mixed $invoiceDate,
        public float|string $subtotal,
        public float|string $taxAmount,
        public float|string $totalAmount,
        public ?string $customerTaxId = null,
        public ?string $customerName = null,
        public ?string $customerAddress = null,
    ) {}

    public function getId(): int
    {
        return $this->id;
    }

    public function getTenantId(): int
    {
        return $this->tenantId;
    }

    public function getInvoiceNumber(): string
    {
        return $this->invoiceNumber;
    }

    public function getTaxInvoiceSerialNumber(): ?string
    {
        return $this->taxInvoiceSerialNumber;
    }

    public function setTaxInvoiceSerialNumber(?string $serialNumber): void
    {
        // Immutable DTO snapshot
    }

    public function getInvoiceDate(): mixed
    {
        return $this->invoiceDate;
    }

    public function getSubtotal(): float|string
    {
        return $this->subtotal;
    }

    public function getTaxAmount(): float|string
    {
        return $this->taxAmount;
    }

    public function getTotalAmount(): float|string
    {
        return $this->totalAmount;
    }

    public function getCustomerTaxId(): ?string
    {
        return $this->customerTaxId;
    }

    public function getCustomerName(): ?string
    {
        return $this->customerName;
    }

    public function getCustomerAddress(): ?string
    {
        return $this->customerAddress;
    }
}
