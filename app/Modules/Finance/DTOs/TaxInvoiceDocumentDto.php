<?php

declare(strict_types=1);

namespace App\Modules\Finance\DTOs;

class TaxInvoiceDocumentDto
{
    /**
     * @param list<TaxInvoiceItemDto> $items
     */
    public function __construct(
        public int $tenantId,
        public int $invoiceId,
        public string $invoiceNumber,
        public ?string $taxInvoiceSerialNumber,
        public string $invoiceDate,
        public int $taxPeriodMonth,
        public int $taxPeriodYear,
        public string $transactionType,
        public bool $isReplacement,
        public string $counterpartyName,
        public ?string $counterpartyTaxId,
        public ?string $counterpartyAddress,
        public string $dppAmount,
        public string $ppnAmount,
        public string $ppnbmAmount = '0.00',
        public string $reference = '',
        public array $items = []
    ) {}
}

