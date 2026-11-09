<?php

declare(strict_types=1);

namespace App\Modules\Finance\Contracts;

use App\Modules\Finance\DTOs\TaxInvoiceDocumentDto;
use App\Modules\Finance\DTOs\TaxSubmissionResultDto;

interface TaxIntegrationServiceInterface
{
    /**
     * Hitung pajak secara kanonik dengan desimal eksak.
     *
     * @return array{
     *     taxable_base: string,
     *     tax_amount: string,
     *     total_amount: string,
     *     effective_rate: string
     * }
     */
    public function calculateTax(
        float|string $taxableAmount,
        float|string $taxRatePercent,
        bool $isInclusive = false
    ): array;

    /**
     * Daftarkan adapter penyedia pajak.
     */
    public function registerAdapter(TaxProviderAdapterInterface $adapter): void;

    /**
     * Dapatkan adapter penyedia pajak yang terdaftar.
     */
    public function getAdapter(?string $name = null): TaxProviderAdapterInterface;

    /**
     * Submit faktur pajak dengan perlindungan idempotensi, pencatatan audit, dan penyensoran data sensitif (PII).
     *
     * @param array<string, mixed> $options
     */
    public function submitTaxInvoice(
        TaxInvoiceDocumentDto $document,
        string $idempotencyKey,
        ?string $providerName = null,
        ?int $companyId = null,
        array $options = []
    ): TaxSubmissionResultDto;

    /**
     * Dapatkan status penyerahan faktur pajak dari referensi pengajuan.
     */
    public function getSubmissionStatus(int $tenantId, string $submissionReference): TaxSubmissionResultDto;

    /**
     * Batalkan faktur pajak yang telah diserahkan.
     */
    public function cancelTaxInvoice(
        int $tenantId,
        string $taxInvoiceSerialNumber,
        string $reason,
        ?string $providerName = null
    ): TaxSubmissionResultDto;

    /**
     * Sensor data sensitif (NPWP, NIK, kunci rahasia) sebelum disimpan ke log / database.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function maskSensitivePayload(array $payload): array;
}
