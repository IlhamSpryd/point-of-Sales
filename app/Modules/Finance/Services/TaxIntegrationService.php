<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Contracts\TaxCalculationServiceInterface;
use App\Modules\Finance\Contracts\TaxIntegrationServiceInterface;
use App\Modules\Finance\Contracts\TaxProviderAdapterInterface;
use App\Modules\Finance\DTOs\TaxInvoiceDocumentDto;
use App\Modules\Finance\DTOs\TaxSubmissionResultDto;
use App\Modules\Finance\Models\TaxSubmission;
use App\Modules\Finance\Services\TaxAdapters\CoreTaxPjapAdapter;
use App\Modules\Finance\Services\TaxAdapters\InternalTaxWorkflowAdapter;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class TaxIntegrationService implements TaxIntegrationServiceInterface
{
    /**
     * @var array<string, TaxProviderAdapterInterface>
     */
    protected array $adapters = [];

    public function __construct(
        protected TaxCalculationServiceInterface $calculationService,
        ?TaxProviderAdapterInterface $defaultAdapter = null
    ) {
        $internal = $defaultAdapter ?? new InternalTaxWorkflowAdapter();
        $this->registerAdapter($internal);
        $this->registerAdapter(new CoreTaxPjapAdapter());
    }

    public function calculateTax(
        float|string $taxableAmount,
        float|string $taxRatePercent,
        bool $isInclusive = false
    ): array {
        return $this->calculationService->calculateTax($taxableAmount, $taxRatePercent, $isInclusive);
    }

    public function registerAdapter(TaxProviderAdapterInterface $adapter): void
    {
        $this->adapters[$adapter->getProviderName()] = $adapter;
    }

    public function getAdapter(?string $name = null): TaxProviderAdapterInterface
    {
        $targetName = $name ?: 'internal';

        if (! isset($this->adapters[$targetName])) {
            throw new InvalidArgumentException("Tax provider adapter '{$targetName}' is not registered.");
        }

        return $this->adapters[$targetName];
    }

    /**
     * Submit tax document with provider resolution, idempotency protection, and sensitive data masking.
     *
     * @param array<string, mixed> $options
     */
    public function submitTaxInvoice(
        TaxInvoiceDocumentDto $document,
        string $idempotencyKey,
        ?string $providerName = null,
        ?int $companyId = null,
        array $options = []
    ): TaxSubmissionResultDto {
        if ($idempotencyKey === '') {
            throw new InvalidArgumentException('Idempotency key must not be empty.');
        }

        // 1. Perlindungan Idempotensi: Cek apakah submission dengan idempotency key ini sudah pernah diproses
        $existing = TaxSubmission::withoutGlobalScopes()
            ->where('tenant_id', $document->tenantId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing && $existing->status !== 'FAILED') {
            return new TaxSubmissionResultDto(
                providerName: $existing->provider_name,
                submissionReference: $existing->submission_reference,
                status: $existing->status,
                taxInvoiceSerialNumber: $existing->tax_invoice_serial_number,
                rawPayloadHash: $existing->raw_payload_hash,
                requestLog: $existing->request_payload_masked ?? [],
                responseLog: $existing->response_payload_masked ?? [],
                errorMessage: $existing->last_error,
                submittedAt: $existing->submitted_at?->toIso8601String()
            );
        }

        $adapter = $this->getAdapter($providerName);

        try {
            // 2. Eksekusi pengajuan melalui provider adapter
            $result = $adapter->submitInvoice($document, $options);

            // 3. Sensor data sensitif (PII & Credentials) pada request & response payload
            $maskedRequest = $this->maskSensitivePayload($result->requestLog);
            $maskedResponse = $this->maskSensitivePayload($result->responseLog);

            // 4. Catat atau perbarui riwayat pengajuan faktur pajak ke database
            if ($existing) {
                $existing->update([
                    'provider_name' => $adapter->getProviderName(),
                    'submission_reference' => $result->submissionReference,
                    'tax_invoice_serial_number' => $result->taxInvoiceSerialNumber,
                    'status' => $result->status,
                    'raw_payload_hash' => $result->rawPayloadHash,
                    'request_payload_masked' => $maskedRequest,
                    'response_payload_masked' => $maskedResponse,
                    'retry_count' => $existing->retry_count + 1,
                    'last_error' => $result->errorMessage,
                    'submitted_at' => now(),
                ]);
            } else {
                TaxSubmission::withoutGlobalScopes()->create([
                    'tenant_id' => $document->tenantId,
                    'company_id' => $companyId,
                    'provider_name' => $adapter->getProviderName(),
                    'idempotency_key' => $idempotencyKey,
                    'submission_reference' => $result->submissionReference,
                    'invoice_id' => $document->invoiceId,
                    'invoice_number' => $document->invoiceNumber,
                    'tax_invoice_serial_number' => $result->taxInvoiceSerialNumber,
                    'status' => $result->status,
                    'raw_payload_hash' => $result->rawPayloadHash,
                    'request_payload_masked' => $maskedRequest,
                    'response_payload_masked' => $maskedResponse,
                    'retry_count' => 0,
                    'last_error' => $result->errorMessage,
                    'submitted_at' => now(),
                ]);
            }

            return new TaxSubmissionResultDto(
                providerName: $result->providerName,
                submissionReference: $result->submissionReference,
                status: $result->status,
                taxInvoiceSerialNumber: $result->taxInvoiceSerialNumber,
                rawPayloadHash: $result->rawPayloadHash,
                requestLog: $maskedRequest,
                responseLog: $maskedResponse,
                errorMessage: $result->errorMessage,
                submittedAt: now()->toIso8601String()
            );
        } catch (Throwable $e) {
            // Catat kegagalan ke database untuk audit trail & mekanisme retry
            $rawHash = hash('sha256', $document->invoiceNumber.':'.$idempotencyKey);
            $failedReference = 'ERR-TAX-'.now()->format('Ymd').'-'.uniqid();

            $maskedRequest = $this->maskSensitivePayload([
                'invoice_number' => $document->invoiceNumber,
                'counterparty_tax_id' => $document->counterpartyTaxId,
                'counterparty_name' => $document->counterpartyName,
            ]);

            if ($existing) {
                $existing->update([
                    'retry_count' => $existing->retry_count + 1,
                    'last_error' => $e->getMessage(),
                    'response_payload_masked' => ['error' => $e->getMessage()],
                    'updated_at' => now(),
                ]);
            } else {
                TaxSubmission::withoutGlobalScopes()->create([
                    'tenant_id' => $document->tenantId,
                    'company_id' => $companyId,
                    'provider_name' => $adapter->getProviderName(),
                    'idempotency_key' => $idempotencyKey,
                    'submission_reference' => $failedReference,
                    'invoice_id' => $document->invoiceId,
                    'invoice_number' => $document->invoiceNumber,
                    'tax_invoice_serial_number' => $document->taxInvoiceSerialNumber,
                    'status' => 'FAILED',
                    'raw_payload_hash' => $rawHash,
                    'request_payload_masked' => $maskedRequest,
                    'response_payload_masked' => ['error' => $e->getMessage()],
                    'retry_count' => 1,
                    'last_error' => $e->getMessage(),
                    'submitted_at' => now(),
                ]);
            }

            throw $e;
        }
    }

    public function getSubmissionStatus(int $tenantId, string $submissionReference): TaxSubmissionResultDto
    {
        $submission = TaxSubmission::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('submission_reference', $submissionReference)
            ->first();

        if (! $submission) {
            throw new DomainException("Tax submission reference '{$submissionReference}' not found.");
        }

        $adapter = $this->getAdapter($submission->provider_name);
        $result = $adapter->getSubmissionStatus($submissionReference);

        return new TaxSubmissionResultDto(
            providerName: $submission->provider_name,
            submissionReference: $submissionReference,
            status: $result->status,
            taxInvoiceSerialNumber: $submission->tax_invoice_serial_number,
            rawPayloadHash: $submission->raw_payload_hash,
            requestLog: $this->maskSensitivePayload($result->requestLog),
            responseLog: $this->maskSensitivePayload($result->responseLog),
            errorMessage: $result->errorMessage,
            submittedAt: $submission->submitted_at?->toIso8601String()
        );
    }

    public function cancelTaxInvoice(
        int $tenantId,
        string $taxInvoiceSerialNumber,
        string $reason,
        ?string $providerName = null
    ): TaxSubmissionResultDto {
        $adapter = $this->getAdapter($providerName);
        $result = $adapter->cancelInvoice($taxInvoiceSerialNumber, $reason);

        TaxSubmission::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('tax_invoice_serial_number', $taxInvoiceSerialNumber)
            ->update([
                'status' => 'CANCELLED',
                'last_error' => "Cancelled: {$reason}",
                'updated_at' => now(),
            ]);

        return $result;
    }

    /**
     * Sensor data sensitif (NPWP, NIK, kunci rahasia) sebelum disimpan ke log / database.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function maskSensitivePayload(array $payload): array
    {
        $masked = [];
        $sensitiveKeys = ['secret', 'api_key', 'password', 'token', 'bearer', 'authorization', 'signature', 'private_key'];

        foreach ($payload as $key => $value) {
            $lowerKey = strtolower((string) $key);

            if (is_array($value)) {
                $masked[$key] = $this->maskSensitivePayload($value);

                continue;
            }

            // Kunci kredensial rahasia
            foreach ($sensitiveKeys as $sensitive) {
                if (str_contains($lowerKey, $sensitive)) {
                    $masked[$key] = '[REDACTED]';

                    continue 2;
                }
            }

            // Sensor format NPWP atau NIK (15 atau 16 digit)
            if (is_string($value) && (str_contains($lowerKey, 'npwp') || str_contains($lowerKey, 'tax_id') || str_contains($lowerKey, 'nik'))) {
                $clean = preg_replace('/\D/', '', $value);
                if ($clean !== null && in_array(strlen($clean), [15, 16], true)) {
                    $len = strlen($clean);
                    $prefix = substr($clean, 0, 2);
                    $suffix = substr($clean, -3);
                    $masked[$key] = $prefix.str_repeat('*', $len - 5).$suffix;

                    continue;
                }
            }

            $masked[$key] = $value;
        }

        return $masked;
    }
}
