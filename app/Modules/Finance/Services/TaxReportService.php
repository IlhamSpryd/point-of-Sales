<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Contracts\TaxIntegrationGatewayInterface;
use App\Modules\Sales\Models\CustomerInvoice;

class TaxReportService
{
    public function __construct(
        protected TaxIntegrationGatewayInterface $gateway
    ) {}

    /**
     * Menghasilkan teks CSV e-Faktur resmi DJP untuk pelaporan SPT Masa PPN.
     */
    public function generateMonthlyEFakturCsv(int $tenantId, int $month, int $year): string
    {
        return $this->gateway->exportCsv($tenantId, $month, $year, 'outbound');
    }

    /**
     * Mengalokasikan dan menetapkan NSFP resmi pada CustomerInvoice.
     */
    public function assignTaxInvoiceSerial(CustomerInvoice $invoice): string
    {
        $doc = $this->gateway->buildDocumentFromCustomerInvoice($invoice);

        return (string) $doc->taxInvoiceSerialNumber;
    }

    /**
     * Memvalidasi format NPWP pelanggan atau pemasok.
     */
    public function validateNpwp(string $npwp): bool
    {
        return $this->gateway->validateTaxId($npwp);
    }

    /**
     * Memformat NPWP menjadi format standar DJP.
     */
    public function formatNpwp(string $npwp): string
    {
        return $this->gateway->formatTaxId($npwp);
    }

    /**
     * Mempersiapkan payload sinkronisasi CoreTax DJP masa depan.
     *
     * @return array<string, mixed>
     */
    public function prepareCoreTaxPayload(CustomerInvoice $invoice): array
    {
        $doc = $this->gateway->buildDocumentFromCustomerInvoice($invoice);

        return $this->gateway->syncWithCoreTax($doc);
    }
}

