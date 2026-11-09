<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Modules\Core\Models\Company;
use App\Modules\Finance\Contracts\TaxCalculationServiceInterface;
use App\Modules\Finance\Contracts\TaxIntegrationServiceInterface;
use App\Modules\Finance\DTOs\TaxInvoiceDocumentDto;
use App\Modules\Finance\DTOs\TaxInvoiceItemDto;
use App\Modules\Finance\Models\TaxSubmission;
use App\Modules\Finance\Services\TaxAdapters\CoreTaxPjapAdapter;
use App\Modules\Finance\Services\TaxAdapters\InternalTaxWorkflowAdapter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class TaxIntegrationArchitectureTest extends TestCase
{
    use DatabaseTransactions;

    private TaxIntegrationServiceInterface $taxIntegrationService;
    private TaxCalculationServiceInterface $taxCalculationService;
    private Company $defaultCompany;

    protected function afterSubclassSetUp(): void
    {
        $this->taxIntegrationService = app(TaxIntegrationServiceInterface::class);
        $this->taxCalculationService = app(TaxCalculationServiceInterface::class);

        $this->defaultCompany = Company::firstOrCreate(
            ['tenant_id' => $this->defaultTenant->id, 'is_default' => true],
            [
                'company_code' => 'CMP-MAIN',
                'company_name' => 'PT Apeiron Solusi Utama',
                'legal_name' => 'PT Apeiron Solusi Utama Indonesia',
                'npwp' => '012345678901000',
                'tax_address' => 'Sudirman Central Business District Jakarta',
                'is_active' => true,
            ]
        );
    }

    public function test_exact_decimal_tax_calculation_exclusive_and_inclusive(): void
    {
        // 1. Exclusive 11% PPN (100.000 + 11.000 = 111.000)
        $exclusive = $this->taxCalculationService->calculateTax('100000.00', '11.0000', false);
        $this->assertSame('100000.00', $exclusive['taxable_base']);
        $this->assertSame('11000.00', $exclusive['tax_amount']);
        $this->assertSame('111000.00', $exclusive['total_amount']);

        // 2. Inclusive 11% PPN (Total 111.000 -> DPP 100.000 + PPN 11.000)
        $inclusive = $this->taxCalculationService->calculateTax('111000.00', '11.0000', true);
        $this->assertSame('100000.00', $inclusive['taxable_base']);
        $this->assertSame('11000.00', $inclusive['tax_amount']);
        $this->assertSame('111000.00', $inclusive['total_amount']);

        // 3. Line items batch calculation
        $lineResults = $this->taxCalculationService->calculateLineItemsTax([
            [
                'line_id' => 1,
                'unit_price' => '50000.00',
                'quantity' => '2',
                'discount_amount' => '10000.00', // Net = 90.000
                'tax_rate_percent' => '11.00',
            ],
            [
                'line_id' => 2,
                'unit_price' => '200000.00',
                'quantity' => '1',
                'discount_amount' => '0.00', // Net = 200.000
                'tax_rate_percent' => '11.00',
            ],
        ], false);

        $this->assertSame('290000.00', $lineResults['total_taxable_base']);
        $this->assertSame('31900.00', $lineResults['total_tax_amount']);
        $this->assertSame('321900.00', $lineResults['grand_total']);
        $this->assertCount(2, $lineResults['lines']);
    }

    public function test_submit_tax_invoice_via_internal_workflow_persists_submission_and_masks_pii(): void
    {
        $idempotencyKey = 'tax-idemp-'.uniqid();
        $rawNpwp = '012345678901000'; // 15 digit NPWP
        $doc = new TaxInvoiceDocumentDto(
            tenantId: $this->defaultTenant->id,
            invoiceId: 991,
            invoiceNumber: 'INV/2027/04/001',
            taxInvoiceSerialNumber: '010.000-27.00000001',
            invoiceDate: now()->toDateString(),
            taxPeriodMonth: 4,
            taxPeriodYear: 2027,
            transactionType: '01',
            isReplacement: false,
            counterpartyName: 'PT Mitra Sejahtera Kencana',
            counterpartyTaxId: $rawNpwp,
            counterpartyAddress: 'Jl. Thamrin No. 45 Jakarta',
            dppAmount: '1000000.00',
            ppnAmount: '110000.00',
            items: [
                new TaxInvoiceItemDto('SRV-01', 'Cloud Software License', '1000000.00', '1.00', '1000000.00', '0.00', '1000000.00', '110000.00'),
            ]
        );

        $result = $this->taxIntegrationService->submitTaxInvoice(
            document: $doc,
            idempotencyKey: $idempotencyKey,
            providerName: 'internal',
            companyId: $this->defaultCompany->id,
            options: ['secret_token' => 'top-secret-token-123']
        );

        $this->assertTrue($result->isSuccess());
        $this->assertSame('INTERNAL_RECORDED', $result->status);
        $this->assertSame('010.000-27.00000001', $result->taxInvoiceSerialNumber);
        $this->assertStringStartsWith('INT-TAX-', $result->submissionReference);

        // 1. Verifikasi data tersimpan di tabel database tax_submissions
        $this->assertDatabaseHas('tax_submissions', [
            'tenant_id' => $this->defaultTenant->id,
            'company_id' => $this->defaultCompany->id,
            'provider_name' => 'internal',
            'idempotency_key' => $idempotencyKey,
            'submission_reference' => $result->submissionReference,
            'status' => 'INTERNAL_RECORDED',
            'tax_invoice_serial_number' => '010.000-27.00000001',
        ]);

        // 2. Verifikasi sensor data sensitif (PII & Secret Protection)
        $submission = TaxSubmission::where('idempotency_key', $idempotencyKey)->firstOrFail();
        $maskedReq = $submission->request_payload_masked;

        // Pastikan token rahasia tidak pernah terekspos mentah
        $this->assertArrayNotHasKey('secret_token', $maskedReq ?? []);
    }

    public function test_sensitive_payload_masking_protects_npwp_and_secret_keys(): void
    {
        $sensitiveData = [
            'client_id' => 'client_abc',
            'api_key' => 'live_sk_999888777666',
            'client_secret' => 'super_secret_auth',
            'customer_npwp' => '012345678901000', // 15 digits
            'nik_tax_id' => '3171012345678901', // 16 digits
            'public_info' => 'PT Maju Bersama',
        ];

        $masked = $this->taxIntegrationService->maskSensitivePayload($sensitiveData);

        $this->assertSame('[REDACTED]', $masked['api_key']);
        $this->assertSame('[REDACTED]', $masked['client_secret']);
        $this->assertSame('01**********000', $masked['customer_npwp']);
        $this->assertSame('31***********901', $masked['nik_tax_id']);
        $this->assertSame('PT Maju Bersama', $masked['public_info']);
    }

    public function test_idempotency_protection_prevents_duplicate_submissions(): void
    {
        $idempotencyKey = 'tax-repeat-'.uniqid();
        $doc = new TaxInvoiceDocumentDto(
            tenantId: $this->defaultTenant->id,
            invoiceId: 992,
            invoiceNumber: 'INV/2027/04/002',
            taxInvoiceSerialNumber: '010.000-27.00000002',
            invoiceDate: now()->toDateString(),
            taxPeriodMonth: 4,
            taxPeriodYear: 2027,
            transactionType: '01',
            isReplacement: false,
            counterpartyName: 'PT Global Niaga',
            counterpartyTaxId: '023456789012000',
            counterpartyAddress: 'Gatot Subroto Jakarta',
            dppAmount: '500000.00',
            ppnAmount: '55000.00'
        );

        // Eksekusi pertama
        $firstResult = $this->taxIntegrationService->submitTaxInvoice(
            document: $doc,
            idempotencyKey: $idempotencyKey,
            companyId: $this->defaultCompany->id
        );

        // Eksekusi kedua dengan key identik
        $secondResult = $this->taxIntegrationService->submitTaxInvoice(
            document: $doc,
            idempotencyKey: $idempotencyKey,
            companyId: $this->defaultCompany->id
        );

        $this->assertSame($firstResult->submissionReference, $secondResult->submissionReference);
        $this->assertSame($firstResult->status, $secondResult->status);
        $this->assertSame($firstResult->rawPayloadHash, $secondResult->rawPayloadHash);

        // Pastikan hanya satu record yang tersimpan di database
        $count = TaxSubmission::where('tenant_id', $this->defaultTenant->id)
            ->where('idempotency_key', $idempotencyKey)
            ->count();
        $this->assertSame(1, $count);
    }

    public function test_coretax_pjap_adapter_submission_and_cancellation(): void
    {
        $idempotencyKey = 'pjap-test-'.uniqid();
        $doc = new TaxInvoiceDocumentDto(
            tenantId: $this->defaultTenant->id,
            invoiceId: 993,
            invoiceNumber: 'INV/2027/04/003',
            taxInvoiceSerialNumber: '010.000-27.00000003',
            invoiceDate: now()->toDateString(),
            taxPeriodMonth: 4,
            taxPeriodYear: 2027,
            transactionType: '01',
            isReplacement: false,
            counterpartyName: 'PT Mandiri Sentosa',
            counterpartyTaxId: '034567890123000',
            counterpartyAddress: 'Kuningan Jakarta',
            dppAmount: '2000000.00',
            ppnAmount: '220000.00'
        );

        $result = $this->taxIntegrationService->submitTaxInvoice(
            document: $doc,
            idempotencyKey: $idempotencyKey,
            providerName: 'coretax_pjap',
            companyId: $this->defaultCompany->id
        );

        $this->assertSame('coretax_pjap', $result->providerName);
        $this->assertStringStartsWith('PJAP-', $result->submissionReference);

        // Uji alur pembatalan faktur pajak
        $cancelResult = $this->taxIntegrationService->cancelTaxInvoice(
            tenantId: $this->defaultTenant->id,
            taxInvoiceSerialNumber: '010.000-27.00000003',
            reason: 'Koreksi spesifikasi pesanan oleh pembeli',
            providerName: 'coretax_pjap'
        );

        $this->assertSame('CANCELLED', $cancelResult->status);
        $this->assertDatabaseHas('tax_submissions', [
            'tenant_id' => $this->defaultTenant->id,
            'tax_invoice_serial_number' => '010.000-27.00000003',
            'status' => 'CANCELLED',
        ]);
    }

    public function test_tax_submission_retries_on_failure_with_same_idempotency_key(): void
    {
        $idempotencyKey = 'retry-test-'.uniqid();
        $doc = new TaxInvoiceDocumentDto(
            tenantId: $this->defaultTenant->id,
            invoiceId: 994,
            invoiceNumber: 'INV/2027/04/004',
            taxInvoiceSerialNumber: '010.000-27.00000004',
            invoiceDate: now()->toDateString(),
            taxPeriodMonth: 4,
            taxPeriodYear: 2027,
            transactionType: '01',
            isReplacement: false,
            counterpartyName: 'PT Retry Sukses Mandiri',
            counterpartyTaxId: '098765432109000',
            counterpartyAddress: 'Gatot Subroto Jakarta',
            dppAmount: '500000.00',
            ppnAmount: '55000.00'
        );

        // Adapter mock: Gagal pada panggilan pertama, berhasil pada panggilan kedua
        $flakyAdapter = new class implements \App\Modules\Finance\Contracts\TaxProviderAdapterInterface {
            public int $attempts = 0;
            public function getProviderName(): string { return 'flaky_mock'; }
            public function calculateTax(float|string $taxableAmount, float|string $taxRatePercent, bool $isInclusive = false): array { return []; }
            public function submitInvoice(TaxInvoiceDocumentDto $document, array $options = []): \App\Modules\Finance\DTOs\TaxSubmissionResultDto {
                $this->attempts++;
                if ($this->attempts === 1) {
                    throw new \RuntimeException('Gateway timeout to CoreTax DJP sandbox.');
                }
                return new \App\Modules\Finance\DTOs\TaxSubmissionResultDto(
                    providerName: 'flaky_mock',
                    submissionReference: 'FLK-SUCCESS-001',
                    status: 'SUBMITTED',
                    taxInvoiceSerialNumber: $document->taxInvoiceSerialNumber,
                    rawPayloadHash: hash('sha256', 'success'),
                    requestLog: ['doc' => $document->invoiceNumber],
                    responseLog: ['status' => 'OK'],
                    submittedAt: now()->toIso8601String()
                );
            }
            public function getSubmissionStatus(string $submissionReference): \App\Modules\Finance\DTOs\TaxSubmissionResultDto {
                return new \App\Modules\Finance\DTOs\TaxSubmissionResultDto('flaky_mock', $submissionReference, 'SUBMITTED', null, '', [], []);
            }
            public function cancelInvoice(string $taxInvoiceSerialNumber, string $reason): \App\Modules\Finance\DTOs\TaxSubmissionResultDto {
                return new \App\Modules\Finance\DTOs\TaxSubmissionResultDto('flaky_mock', 'REF-CANCEL', 'CANCELLED', $taxInvoiceSerialNumber, '', [], []);
            }
        };

        $this->taxIntegrationService->registerAdapter($flakyAdapter);

        // 1. Percobaan pertama: Harus melempar exception dan mencatat status FAILED dengan retry_count = 1
        $failed = false;
        try {
            $this->taxIntegrationService->submitTaxInvoice(
                document: $doc,
                idempotencyKey: $idempotencyKey,
                providerName: 'flaky_mock',
                companyId: $this->defaultCompany->id
            );
        } catch (\RuntimeException $e) {
            $failed = true;
            $this->assertStringContainsString('Gateway timeout', $e->getMessage());
        }
        $this->assertTrue($failed);

        $failedRecord = TaxSubmission::withoutGlobalScopes()
            ->where('tenant_id', $this->defaultTenant->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        $this->assertNotNull($failedRecord);
        $this->assertSame('FAILED', $failedRecord->status);
        $this->assertSame(1, $failedRecord->retry_count);

        // 2. Percobaan kedua dengan idempotency key yang sama: Harus memproses ulang (retry) dan berhasil!
        $retryResult = $this->taxIntegrationService->submitTaxInvoice(
            document: $doc,
            idempotencyKey: $idempotencyKey,
            providerName: 'flaky_mock',
            companyId: $this->defaultCompany->id
        );

        $this->assertSame('SUBMITTED', $retryResult->status);
        $this->assertSame('FLK-SUCCESS-001', $retryResult->submissionReference);

        $successRecord = TaxSubmission::withoutGlobalScopes()
            ->where('tenant_id', $this->defaultTenant->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        $this->assertSame('SUBMITTED', $successRecord->status);
        $this->assertSame(2, $successRecord->retry_count);
    }
}
