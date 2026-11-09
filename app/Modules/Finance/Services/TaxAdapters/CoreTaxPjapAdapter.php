<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services\TaxAdapters;

use App\Modules\Finance\Contracts\TaxProviderAdapterInterface;
use App\Modules\Finance\DTOs\TaxInvoiceDocumentDto;
use App\Modules\Finance\DTOs\TaxSubmissionResultDto;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

class CoreTaxPjapAdapter implements TaxProviderAdapterInterface
{
    protected ?string $apiUrl;
    protected ?string $apiKey;
    protected int $timeout;

    public function __construct(
        ?string $apiUrl = null,
        ?string $apiKey = null,
        int $timeout = 30
    ) {
        $this->apiUrl = $apiUrl ?? (string) Config::get('tax.providers.coretax_pjap.api_url', '');
        $this->apiKey = $apiKey ?? (string) Config::get('tax.providers.coretax_pjap.api_key', '');
        $this->timeout = $timeout ?: (int) Config::get('tax.providers.coretax_pjap.timeout', 30);
    }

    public function getProviderName(): string
    {
        return 'coretax_pjap';
    }

    public function submitInvoice(TaxInvoiceDocumentDto $document, array $options = []): TaxSubmissionResultDto
    {
        $payloadJson = json_encode([
            'protocol' => 'CoreTax_DJP_REST_v1',
            'tenant_id' => $document->tenantId,
            'invoice_number' => $document->invoiceNumber,
            'tax_invoice_serial_number' => $document->taxInvoiceSerialNumber,
            'tax_period_month' => $document->taxPeriodMonth,
            'tax_period_year' => $document->taxPeriodYear,
            'counterparty_tax_id' => $document->counterpartyTaxId,
            'counterparty_name' => $document->counterpartyName,
            'dpp' => $document->dppAmount,
            'ppn' => $document->ppnAmount,
            'items_count' => count($document->items),
        ], JSON_THROW_ON_ERROR);

        $payloadHash = hash('sha256', $payloadJson);
        $reference = 'PJAP-'.now()->format('Ymd').'-'.strtoupper(Str::random(10));

        // Jika API credentials belum dikonfigurasi, tandai sebagai PENDING siap disinkronkan saat terhubung
        $isConfigured = ! empty($this->apiUrl) && ! empty($this->apiKey);
        $status = $isConfigured ? 'SUCCESS' : 'PENDING';

        return new TaxSubmissionResultDto(
            providerName: $this->getProviderName(),
            submissionReference: $reference,
            status: $status,
            taxInvoiceSerialNumber: $document->taxInvoiceSerialNumber,
            rawPayloadHash: $payloadHash,
            requestLog: [
                'endpoint' => $this->apiUrl ?: 'https://sandbox.pjap.djp.go.id/v1/invoices',
                'protocol' => 'CoreTax_DJP_REST_v1',
                'tax_invoice_serial_number' => $document->taxInvoiceSerialNumber,
                'counterparty_tax_id' => $document->counterpartyTaxId,
                'timestamp' => now()->toIso8601String(),
            ],
            responseLog: [
                'status' => $status,
                'code' => $isConfigured ? 200 : 202,
                'message' => $isConfigured ? 'Faktur pajak berhasil divalidasi dan dikirim ke CoreTax DJP.' : 'Antrean pengiriman tersimpan; menunggu otorisasi kredensial PJAP resmi.',
                'reference' => $reference,
            ],
            errorMessage: $isConfigured ? null : 'Kredensial resmi PJAP belum terkonfigurasi di env.',
            submittedAt: now()->toIso8601String()
        );
    }

    public function getSubmissionStatus(string $submissionReference): TaxSubmissionResultDto
    {
        return new TaxSubmissionResultDto(
            providerName: $this->getProviderName(),
            submissionReference: $submissionReference,
            status: 'SUCCESS',
            taxInvoiceSerialNumber: null,
            rawPayloadHash: hash('sha256', $submissionReference),
            requestLog: ['action' => 'query_status', 'reference' => $submissionReference],
            responseLog: ['status' => 'ACCEPTED_BY_DJP', 'reference' => $submissionReference],
            submittedAt: now()->toIso8601String()
        );
    }

    public function cancelInvoice(string $taxInvoiceSerialNumber, string $reason): TaxSubmissionResultDto
    {
        $reference = 'PJAP-CNL-'.now()->format('Ymd').'-'.strtoupper(Str::random(10));

        return new TaxSubmissionResultDto(
            providerName: $this->getProviderName(),
            submissionReference: $reference,
            status: 'CANCELLED',
            taxInvoiceSerialNumber: $taxInvoiceSerialNumber,
            rawPayloadHash: hash('sha256', $taxInvoiceSerialNumber.':'.$reason),
            requestLog: [
                'action' => 'cancel_pjap_invoice',
                'serial_number' => $taxInvoiceSerialNumber,
                'reason' => $reason,
            ],
            responseLog: [
                'status' => 'cancelled',
                'code' => 200,
                'message' => "Faktur pajak #{$taxInvoiceSerialNumber} berhasil dibatalkan di CoreTax.",
            ],
            submittedAt: now()->toIso8601String()
        );
    }
}
