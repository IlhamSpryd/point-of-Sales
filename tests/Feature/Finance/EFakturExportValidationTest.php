<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Modules\Finance\Contracts\TaxIntegrationGatewayInterface;
use App\Modules\Finance\Services\CoreTaxDjpGateway;
use App\Modules\Finance\Services\TaxReportService;
use App\Modules\Sales\Models\CustomerInvoice;
use App\Modules\Sales\Models\CustomerInvoiceLine;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class EFakturExportValidationTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Store $storeA;

    private Store $storeB;

    private Customer $customerA;

    private Customer $customerB;

    private Product $productA;

    private TaxReportService $taxReportService;

    private CoreTaxDjpGateway $gateway;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        app(TenantContext::class)->runWithoutTenant(function () {
            // Tenant A
            $this->tenantA = Tenant::create([
                'name' => 'Tax Integration Tenant A',
                'slug' => 'tax-tenant-a-'.uniqid(),
                'status' => 'active',
                'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
                'access_status' => Tenant::ACCESS_ACTIVE,
            ]);

            $this->storeA = Store::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Store A Jakarta',
                'code' => 'TX-A-'.uniqid(),
                'is_active' => true,
            ]);

            $this->customerA = Customer::create([
                'tenant_id' => $this->tenantA->id,
                'customer_code' => 'CUST-TX-A1',
                'name' => 'PT Mitra Sejahtera Indonesia',
                'company_name' => 'Gedung Cyber Lt 5 Jakarta',
                'tax_id' => '01.234.567.8-901.000',
                'is_active' => true,
            ]);

            $catA = Category::create([
                'tenant_id' => $this->tenantA->id,
                'category_name' => 'Jasa Konsultasi ERP',
            ]);

            $this->productA = Product::create([
                'tenant_id' => $this->tenantA->id,
                'category_id' => $catA->id,
                'product_name' => 'Konsultasi ERP Enterprise Modul 08',
                'product_price' => 1000000,
                'is_active' => true,
            ]);

            // Tenant B
            $this->tenantB = Tenant::create([
                'name' => 'Tax Integration Tenant B',
                'slug' => 'tax-tenant-b-'.uniqid(),
                'status' => 'active',
                'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
                'access_status' => Tenant::ACCESS_ACTIVE,
            ]);

            $this->storeB = Store::create([
                'tenant_id' => $this->tenantB->id,
                'name' => 'Store B Surabaya',
                'code' => 'TX-B-'.uniqid(),
                'is_active' => true,
            ]);

            $this->customerB = Customer::create([
                'tenant_id' => $this->tenantB->id,
                'customer_code' => 'CUST-TX-B1',
                'name' => 'PT Ritel Sentosa Jawa Timur',
                'company_name' => 'Jl Basuki Rahmat Surabaya',
                'tax_id' => '02.987.654.3-210.000',
                'is_active' => true,
            ]);
        });

        app(TenantContext::class)->setTenantId($this->tenantA->id);
        app(TenantContext::class)->setStoreId($this->storeA->id);

        $this->gateway = app(CoreTaxDjpGateway::class);
        $this->taxReportService = app(TaxReportService::class);
    }

    /**
     * DEC-003 / TR-004 Test 1:
     * Validasi dan pemformatan format NPWP 15-digit (konvensional) dan 16-digit (CoreTax / NIK).
     */
    public function test_can_format_and_validate_indonesian_npwp_15_and_16_digits(): void
    {
        // 15 digits
        $this->assertTrue($this->gateway->validateTaxId('01.234.567.8-901.000'));
        $this->assertTrue($this->gateway->validateTaxId('012345678901000'));
        $this->assertEquals('01.234.567.8-901.000', $this->gateway->formatTaxId('012345678901000'));

        // 16 digits (CoreTax NIK-NPWP)
        $this->assertTrue($this->gateway->validateTaxId('3171012345678901'));
        $this->assertEquals('3171012345678901', $this->gateway->formatTaxId('3171012345678901'));

        // Invalid formats
        $this->assertFalse($this->gateway->validateTaxId('12345')); // Terlalu pendek
        $this->assertFalse($this->gateway->validateTaxId('12345678901234567')); // Terlalu panjang
        $this->assertFalse($this->gateway->validateTaxId('')); // Kosong
    }

    /**
     * DEC-003 / TR-004 Test 2:
     * Alokasi Nomor Seri Faktur Pajak (NSFP) standar DJP secara sekuensial per tenant dan tahun.
     */
    public function test_can_generate_tax_invoice_serial_number_for_customer_invoice(): void
    {
        $year = 2026;
        $serial1 = $this->gateway->generateTaxInvoiceSerialNumber($this->tenantA->id, $year, '01', false);
        $this->assertEquals('010.000-26.00000001', $serial1);

        // Buat invoice dengan nomor seri pertama
        CustomerInvoice::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'customer_id' => $this->customerA->id,
            'invoice_number' => 'INV-TX-001',
            'tax_invoice_serial_number' => $serial1,
            'status' => 'POSTED',
            'invoice_date' => '2026-11-01',
            'due_date' => '2026-11-30',
            'subtotal' => '10000000.00',
            'discount_amount' => '0.00',
            'tax_amount' => '1100000.00',
            'shipping_amount' => '0.00',
            'total_amount' => '11100000.00',
            'paid_amount' => '11100000.00',
            'balance_due' => '0.00',
        ]);

        $serial2 = $this->gateway->generateTaxInvoiceSerialNumber($this->tenantA->id, $year, '01', false);
        $this->assertEquals('010.000-26.00000002', $serial2);
    }

    /**
     * DEC-003 / TR-004 Test 3:
     * Ekspor CSV e-Faktur resmi DJP menghasilkan baris FK (Header) dan OF (Objek Faktur) yang valid.
     */
    public function test_efaktur_csv_export_generates_valid_djp_fk_and_of_lines(): void
    {
        $invoice = CustomerInvoice::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'customer_id' => $this->customerA->id,
            'invoice_number' => 'INV-TX-002',
            'status' => 'POSTED',
            'invoice_date' => '2026-11-05',
            'due_date' => '2026-11-30',
            'subtotal' => '2000000.00',
            'discount_amount' => '0.00',
            'tax_amount' => '220000.00',
            'shipping_amount' => '0.00',
            'total_amount' => '2220000.00',
            'paid_amount' => '2220000.00',
            'balance_due' => '0.00',
        ]);

        CustomerInvoiceLine::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantA->id,
            'customer_invoice_id' => $invoice->id,
            'product_id' => $this->productA->id,
            'line_number' => 1,
            'description' => 'Konsultasi ERP Enterprise Modul 08',
            'quantity' => '2',
            'unit_price' => '1000000.00',
            'discount_amount' => '0.00',
            'tax_amount' => '220000.00',
            'line_total' => '2220000.00',
        ]);

        $csv = $this->taxReportService->generateMonthlyEFakturCsv($this->tenantA->id, 11, 2026);

        $this->assertStringContainsString('FK;KD_JENIS_TRANSAKSI;FG_PENGGANTI;NOMOR_FAKTUR', $csv);
        $this->assertStringContainsString('OF;KODE_OBJEK;NAMA;HARGA_SATUAN', $csv);
        $this->assertStringContainsString('PT Mitra Sejahtera Indonesia', $csv);
        $this->assertStringContainsString('012345678901000', $csv); // NPWP mentah 15 digit
        $this->assertStringContainsString('2000000', $csv); // DPP
        $this->assertStringContainsString('220000', $csv); // PPN
        $this->assertStringContainsString('Konsultasi ERP Enterprise Modul 08', $csv);
    }

    /**
     * DEC-003 / TR-004 Test 4:
     * TaxReportService terikat ke kontrak modular TaxIntegrationGatewayInterface dan siap integrasi PJAP CoreTax.
     */
    public function test_tax_gateway_interface_provides_modular_pjap_coretax_contract(): void
    {
        $this->assertInstanceOf(TaxIntegrationGatewayInterface::class, app(TaxIntegrationGatewayInterface::class));

        $invoice = CustomerInvoice::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'customer_id' => $this->customerA->id,
            'invoice_number' => 'INV-TX-003',
            'status' => 'POSTED',
            'invoice_date' => '2026-11-06',
            'due_date' => '2026-11-30',
            'subtotal' => '5000000.00',
            'discount_amount' => '0.00',
            'tax_amount' => '550000.00',
            'shipping_amount' => '0.00',
            'total_amount' => '5550000.00',
            'paid_amount' => '5550000.00',
            'balance_due' => '0.00',
        ]);

        $payload = $this->taxReportService->prepareCoreTaxPayload($invoice);

        $this->assertEquals('prepared', $payload['status']);
        $this->assertEquals('CoreTax_DJP_REST_v1', $payload['protocol']);
        $this->assertTrue($payload['ready_for_pjap_dispatch']);
        $this->assertNotEmpty($payload['payload_hash']);
        $this->assertNotEmpty($payload['tax_invoice_serial_number']);
    }

    /**
     * DEC-003 / TR-004 Test 5:
     * Ekspor e-Faktur menjaga isolasi multi-tenancy secara ketat (fail-closed per tenant_id).
     */
    public function test_efaktur_export_enforces_strict_tenant_isolation(): void
    {
        // Invoice Tenant A
        CustomerInvoice::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'customer_id' => $this->customerA->id,
            'invoice_number' => 'INV-TENANT-A',
            'status' => 'POSTED',
            'invoice_date' => '2026-11-02',
            'due_date' => '2026-11-30',
            'subtotal' => '1000000.00',
            'discount_amount' => '0.00',
            'tax_amount' => '110000.00',
            'shipping_amount' => '0.00',
            'total_amount' => '1110000.00',
            'paid_amount' => '1110000.00',
            'balance_due' => '0.00',
        ]);

        // Invoice Tenant B
        app(TenantContext::class)->setTenantId($this->tenantB->id);
        app(TenantContext::class)->setStoreId($this->storeB->id);

        CustomerInvoice::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantB->id,
            'store_id' => $this->storeB->id,
            'customer_id' => $this->customerB->id,
            'invoice_number' => 'INV-TENANT-B',
            'status' => 'POSTED',
            'invoice_date' => '2026-11-03',
            'due_date' => '2026-11-30',
            'subtotal' => '9000000.00',
            'discount_amount' => '0.00',
            'tax_amount' => '990000.00',
            'shipping_amount' => '0.00',
            'total_amount' => '9990000.00',
            'paid_amount' => '9990000.00',
            'balance_due' => '0.00',
        ]);

        app(TenantContext::class)->setTenantId($this->tenantA->id);
        app(TenantContext::class)->setStoreId($this->storeA->id);

        // Ekspor Tenant A
        $csvA = $this->taxReportService->generateMonthlyEFakturCsv($this->tenantA->id, 11, 2026);
        $this->assertStringContainsString('INV-TENANT-A', $csvA);
        $this->assertStringNotContainsString('INV-TENANT-B', $csvA);
        $this->assertStringNotContainsString('PT Ritel Sentosa Jawa Timur', $csvA);

        // Ekspor Tenant B
        $csvB = $this->taxReportService->generateMonthlyEFakturCsv($this->tenantB->id, 11, 2026);
        $this->assertStringContainsString('INV-TENANT-B', $csvB);
        $this->assertStringNotContainsString('INV-TENANT-A', $csvB);
        $this->assertStringNotContainsString('PT Mitra Sejahtera Indonesia', $csvB);
    }
}
