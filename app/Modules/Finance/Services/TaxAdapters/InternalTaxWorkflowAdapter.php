<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services\TaxAdapters;

use App\Modules\Finance\Contracts\TaxProviderAdapterInterface;
use App\Modules\Finance\DTOs\TaxInvoiceDocumentDto;
use App\Modules\Finance\DTOs\TaxSubmissionResultDto;
use Illuminate\Support\Str;

class InternalTaxWorkflowAdapter implements TaxProviderAdapterInterface
{
    public function getProviderName(): string
    {
        return 'internal';
    }

    /**
     * Memproses faktur pajak secara internal tanpa ketergantungan API pihak ketiga.
     * Mendukung alur kerja manual, export CSV e-Faktur resmi DJP, dan pencatatan buku pembantu pajak.
     */
    public function submitInvoice(TaxInvoiceDocumentDto $document, array $options = []): TaxSubmissionResultDto
    {
        $payloadJson = json_encode([
            'tenant_id' => $document->tenantId,
            'invoice_id' => $document->invoiceId,
            'invoice_number' => $document->invoiceNumber,
            'tax_invoice_serial_number' => $document->taxInvoiceSerialNumber,
            'dpp' => $document->dppAmount,
            'ppn' => $document->ppnAmount,
            'counterparty_name' => $document->counterpartyName,
            'counterparty_tax_id' => $document->counterpartyTaxId,
            'items_count' => count($document->items),
        ], JSON_THROW_ON_ERROR);

        $payloadHash = hash('sha256', $payloadJson);
        $reference = 'INT-TAX-'.now()->format('Ymd').'-'.strtoupper(Str::random(8));

        return new TaxSubmissionResultDto(
            providerName: $this->getProviderName(),
            submissionReference: $reference,
            status: 'INTERNAL_RECORDED',
            taxInvoiceSerialNumber: $document->taxInvoiceSerialNumber,
            rawPayloadHash: $payloadHash,
            requestLog: [
                'action' => 'internal_record',
                'invoice_number' => $document->invoiceNumber,
                'items_count' => count($document->items),
                'timestamp' => now()->toIso8601String(),
            ],
            responseLog: [
                'status' => 'recorded_internally',
                'message' => 'Faktur pajak tersimpan di buku pembantu internal siap untuk ekspor CSV atau rekonsiliasi.',
                'reference' => $reference,
            ],
            submittedAt: now()->toIso8601String()
        );
    }

    public function getSubmissionStatus(string $submissionReference): TaxSubmissionResultDto
    {
        return new TaxSubmissionResultDto(
            providerName: $this->getProviderName(),
            submissionReference: $submissionReference,
            status: 'INTERNAL_RECORDED',
            taxInvoiceSerialNumber: null,
            rawPayloadHash: hash('sha256', $submissionReference),
            requestLog: ['action' => 'query_status', 'reference' => $submissionReference],
            responseLog: ['status' => 'INTERNAL_RECORDED', 'active' => true],
            submittedAt: now()->toIso8601String()
        );
    }

    public function cancelInvoice(string $taxInvoiceSerialNumber, string $reason): TaxSubmissionResultDto
    {
        $reference = 'INT-CNL-'.now()->format('Ymd').'-'.strtoupper(Str::random(8));

        return new TaxSubmissionResultDto(
            providerName: $this->getProviderName(),
            submissionReference: $reference,
            status: 'CANCELLED',
            taxInvoiceSerialNumber: $taxInvoiceSerialNumber,
            rawPayloadHash: hash('sha256', $taxInvoiceSerialNumber.':'.$reason),
            requestLog: [
                'action' => 'cancel_invoice',
                'serial_number' => $taxInvoiceSerialNumber,
                'reason' => $reason,
            ],
            responseLog: [
                'status' => 'cancelled_internally',
                'message' => "Faktur pajak #{$taxInvoiceSerialNumber} berhasil dibatalkan di sistem internal.",
            ],
            submittedAt: now()->toIso8601String()
        );
    }
}
