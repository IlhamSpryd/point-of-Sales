<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Modules\Finance\DTOs\TaxInvoiceDocumentDto;
use App\Modules\Finance\DTOs\TaxInvoiceItemDto;
use App\Modules\Finance\Services\CoreTaxDjpGateway;
use App\Modules\Finance\Services\CoreTaxJsonGenerator;
use App\Modules\Finance\Services\CoreTaxSchemaValidator;
use App\Modules\Finance\Services\CoreTaxXmlGenerator;
use App\Modules\Sales\Models\CustomerInvoice;
use App\Modules\Sales\Models\CustomerInvoiceLine;
use App\Services\Context\TenantContext;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CoreTaxInvoiceGeneratorTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;

    private Store $store;

    private Customer $customer;

    private Product $product;

    private CoreTaxSchemaValidator $validator;

    private CoreTaxXmlGenerator $xmlGenerator;

    private CoreTaxJsonGenerator $jsonGenerator;

    private CoreTaxDjpGateway $gateway;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->validator = new CoreTaxSchemaValidator();
        $this->xmlGenerator = new CoreTaxXmlGenerator($this->validator);
        $this->jsonGenerator = new CoreTaxJsonGenerator($this->validator);
        $this->gateway = new CoreTaxDjpGateway($this->validator, $this->xmlGenerator, $this->jsonGenerator);

        app(TenantContext::class)->runWithoutTenant(function () {
            $this->tenant = Tenant::create([
                'name' => 'PT Surya Mega Tekstil',
                'slug' => 'surya-mega-'.uniqid(),
                'status' => 'active',
                'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
                'access_status' => Tenant::ACCESS_ACTIVE,
            ]);

            $this->store = Store::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Mega Outlet Central',
                'code' => 'OUT-'.uniqid(),
            ]);

            $this->customer = Customer::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'PT Mitra Sejahtera Bersama',
                'phone' => '081234567890',
                'tax_id' => '01.234.567.8-901.000',
                'company_name' => 'Kawasan Industri Pulogadung Kav 12, Jakarta Timur',
            ]);

            $category = Category::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Raw Materials',
            ]);

            $this->product = Product::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id,
                'category_id' => $category->id,
                'product_name' => 'Kain Katun Rayon Premium',
                'product_code' => 'FAB-RAYON-01',
                'product_price' => 50000.00,
                'product_type' => 'standard',
                'is_active' => true,
            ]);
        });

        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(TenantContext::class)->setStoreId($this->store->id);
    }

    public function test_schema_validator_accepts_valid_dto_and_rejects_malformed_dto(): void
    {
        $validDto = new TaxInvoiceDocumentDto(
            tenantId: $this->tenant->id,
            invoiceId: 101,
            invoiceNumber: 'INV-2026-0001',
            taxInvoiceSerialNumber: '010.000-26.00000001',
            invoiceDate: '15/04/2026',
            taxPeriodMonth: 4,
            taxPeriodYear: 2026,
            transactionType: '01',
            isReplacement: false,
            counterpartyName: 'PT Mitra Sejahtera Bersama',
            counterpartyTaxId: '012345678901000',
            counterpartyAddress: 'Jakarta, Indonesia',
            dppAmount: '100000.00',
            ppnAmount: '11000.00',
            ppnbmAmount: '0.00',
            reference: 'PO-9988',
            items: [
                new TaxInvoiceItemDto(
                    code: 'FAB-RAYON-01',
                    name: 'Kain Katun Rayon Premium',
                    unitPrice: '50000.00',
                    quantity: '2.0000',
                    totalPrice: '100000.00',
                    discountAmount: '0.00',
                    dpp: '100000.00',
                    ppn: '11000.00'
                ),
            ]
        );

        $errors = $this->validator->validateDocument($validDto);
        $this->assertEmpty($errors);

        // Test DTO with invalid serial number format
        $invalidDto = new TaxInvoiceDocumentDto(
            tenantId: $this->tenant->id,
            invoiceId: 102,
            invoiceNumber: 'INV-2026-0002',
            taxInvoiceSerialNumber: 'INVALID-SERIAL',
            invoiceDate: '15/04/2026',
            taxPeriodMonth: 4,
            taxPeriodYear: 2026,
            transactionType: '99', // Invalid transaction type
            isReplacement: false,
            counterpartyName: '',
            counterpartyTaxId: '12345', // Invalid length
            counterpartyAddress: 'Jakarta',
            dppAmount: '100000.00',
            ppnAmount: '11000.00',
            ppnbmAmount: '0.00',
            reference: 'REF-02',
            items: []
        );

        $errors = $this->validator->validateDocument($invalidDto);
        $this->assertArrayHasKey('tax_invoice_serial_number', $errors);
        $this->assertArrayHasKey('transaction_type', $errors);
        $this->assertArrayHasKey('counterparty_name', $errors);
        $this->assertArrayHasKey('counterparty_tax_id', $errors);
        $this->assertArrayHasKey('items', $errors);

        $this->expectException(DomainException::class);
        $this->validator->assertValid($invalidDto);
    }

    public function test_xml_generator_produces_valid_djp_efaktur_xml(): void
    {
        $dto = new TaxInvoiceDocumentDto(
            tenantId: $this->tenant->id,
            invoiceId: 201,
            invoiceNumber: 'INV-2026-0099',
            taxInvoiceSerialNumber: '010.000-26.00000099',
            invoiceDate: '20/04/2026',
            taxPeriodMonth: 4,
            taxPeriodYear: 2026,
            transactionType: '01',
            isReplacement: false,
            counterpartyName: 'PT Mitra Sejahtera Bersama',
            counterpartyTaxId: '012345678901000',
            counterpartyAddress: 'Kawasan Industri Pulogadung, Jakarta',
            dppAmount: '500000.00',
            ppnAmount: '55000.00',
            ppnbmAmount: '0.00',
            reference: 'SO-10023',
            items: [
                new TaxInvoiceItemDto(
                    code: 'FAB-RAYON-01',
                    name: 'Kain Katun Rayon Premium',
                    unitPrice: '50000.00',
                    quantity: '10.0000',
                    totalPrice: '500000.00',
                    discountAmount: '0.00',
                    dpp: '500000.00',
                    ppn: '55000.00'
                ),
            ]
        );

        $xml = $this->xmlGenerator->generateXml($dto, [
            'npwp' => '019998887776000',
            'name' => 'PT Surya Mega Tekstil',
            'address' => 'Jl. Sudirman Kav 50, Jakarta',
        ]);

        $this->assertStringContainsString('<?xml version="1.0" encoding="UTF-8"?>', $xml);
        $this->assertStringContainsString('<resmi:fakturPajak', $xml);
        $this->assertStringContainsString('<nomorFaktur>010.000-26.00000099</nomorFaktur>', $xml);
        $this->assertStringContainsString('<npwpPenjual>019998887776000</npwpPenjual>', $xml);
        $this->assertStringContainsString('<npwpPembeli>012345678901000</npwpPembeli>', $xml);
        $this->assertStringContainsString('<jumlahDpp>500000.00</jumlahDpp>', $xml);
        $this->assertStringContainsString('<jumlahPpn>55000.00</jumlahPpn>', $xml);
    }

    public function test_json_generator_produces_compliant_coretax_v1_rest_payload(): void
    {
        $dto = new TaxInvoiceDocumentDto(
            tenantId: $this->tenant->id,
            invoiceId: 301,
            invoiceNumber: 'INV-2026-0301',
            taxInvoiceSerialNumber: '010.000-26.00000301',
            invoiceDate: '25/04/2026',
            taxPeriodMonth: 4,
            taxPeriodYear: 2026,
            transactionType: '01',
            isReplacement: false,
            counterpartyName: 'PT Mandiri Jaya Raya',
            counterpartyTaxId: '3171012345670001', // 16 digit NIK/NPWP per PMK 112/2022
            counterpartyAddress: 'Gedung Wisma 46, Jakarta Pusat',
            dppAmount: '200000.00',
            ppnAmount: '22000.00',
            ppnbmAmount: '0.00',
            reference: 'PO-301',
            items: [
                new TaxInvoiceItemDto(
                    code: 'FAB-RAYON-01',
                    name: 'Kain Katun Rayon Premium',
                    unitPrice: '100000.00',
                    quantity: '2.0000',
                    totalPrice: '200000.00',
                    discountAmount: '0.00',
                    dpp: '200000.00',
                    ppn: '22000.00'
                ),
            ]
        );

        $payload = $this->jsonGenerator->generatePayload($dto);

        $this->assertSame('CoreTax_DJP_REST_v1', $payload['protocol']);
        $this->assertSame('1.0.0', $payload['schema_version']);
        $this->assertSame('NIK_16', $payload['buyer']['tax_id_type']);
        $this->assertSame('3171012345670001', $payload['buyer']['tax_id']);
        $this->assertSame('200000.00', $payload['tax_summary']['dpp_total']);
        $this->assertSame('22000.00', $payload['tax_summary']['ppn_total']);
        $this->assertNotEmpty($payload['payload_hash']);

        $jsonString = $this->jsonGenerator->generateJson($dto);
        $this->assertJson($jsonString);
    }

    public function test_gateway_sync_with_coretax_and_batch_export(): void
    {
        // Create customer invoice with lines
        $invoice = CustomerInvoice::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'INV-TEST-'.uniqid(),
            'invoice_date' => '2026-04-10',
            'due_date' => '2026-05-10',
            'status' => 'POSTED',
            'subtotal' => 100000.00,
            'discount_amount' => 0.00,
            'tax_amount' => 11000.00,
            'total_amount' => 111000.00,
            'tax_invoice_serial_number' => '010.000-26.00000555',
            'tax_invoice_status' => 'generated',
        ]);

        CustomerInvoiceLine::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'customer_invoice_id' => $invoice->id,
            'line_number' => 1,
            'product_id' => $this->product->id,
            'description' => 'Kain Katun Rayon Premium',
            'quantity' => 2.0000,
            'unit_price' => 50000.00,
            'subtotal' => 100000.00,
            'discount_amount' => 0.00,
            'tax_amount' => 11000.00,
            'total' => 111000.00,
        ]);

        $doc = $this->gateway->buildDocumentFromCustomerInvoice($invoice);
        $syncResult = $this->gateway->syncWithCoreTax($doc);

        $this->assertSame('prepared', $syncResult['status']);
        $this->assertSame('validated', $syncResult['validation_status']);
        $this->assertSame('CoreTax_DJP_REST_v1', $syncResult['protocol']);
        $this->assertTrue($syncResult['ready_for_pjap_dispatch']);
        $this->assertNotEmpty($syncResult['payload_hash']);
        $this->assertArrayHasKey('payload', $syncResult);

        // Test batch XML export
        $batchXml = $this->gateway->exportXml(
            tenantId: $this->tenant->id,
            taxPeriodMonth: 4,
            taxPeriodYear: 2026
        );

        $this->assertStringContainsString('<resmi:batchFakturPajak', $batchXml);
        $this->assertStringContainsString('010.000-26.00000555', $batchXml);

        // Test batch JSON export
        $batchJson = $this->gateway->exportJson(
            tenantId: $this->tenant->id,
            taxPeriodMonth: 4,
            taxPeriodYear: 2026
        );

        $this->assertCount(1, $batchJson);
        $this->assertSame('010.000-26.00000555', $batchJson[0]['header']['tax_invoice_serial_number']);
    }

    public function test_customer_invoice_with_null_discount_and_multi_lines_generates_valid_coretax_document(): void
    {
        // Test invoice without lines (empty lines fallback) and zero discount_amount
        $invoiceNoLines = CustomerInvoice::forceCreate([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'INV-TX-NULLDISC-'.uniqid(),
            'invoice_date' => '2026-04-10',
            'due_date' => '2026-05-10',
            'subtotal' => 200000.00,
            'discount_amount' => 0.00,
            'tax_amount' => 22000.00,
            'total_amount' => 222000.00,
            'status' => 'POSTED',
            'tax_invoice_serial_number' => '010.000-26.00000999',
        ]);

        $docNoLines = $this->gateway->buildDocumentFromCustomerInvoice($invoiceNoLines);
        $this->assertSame('200000.00', $docNoLines->dppAmount);
        $this->assertSame('22000.00', $docNoLines->ppnAmount);
        $this->validator->assertValid($docNoLines);

        // Test multi-line invoice with distinct line discounts
        $multiInvoice = CustomerInvoice::forceCreate([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'INV-TX-MULTI-'.uniqid(),
            'invoice_date' => '2026-04-12',
            'due_date' => '2026-05-12',
            'subtotal' => 300000.00,
            'discount_amount' => 15000.00,
            'tax_amount' => 31350.00,
            'total_amount' => 316350.00,
            'status' => 'POSTED',
            'tax_invoice_serial_number' => '010.000-26.00000998',
        ]);

        // Line 1: 200,000 - 10,000 disc = 190,000 DPP, 20,900 PPN
        CustomerInvoiceLine::create([
            'tenant_id' => $this->tenant->id,
            'customer_invoice_id' => $multiInvoice->id,
            'line_number' => 1,
            'product_id' => $this->product->id,
            'description' => 'Line 1 Item',
            'quantity' => 2.0000,
            'unit_price' => 100000.00,
            'discount_amount' => 10000.00,
            'tax_amount' => 20900.00,
            'line_total' => 210900.00,
        ]);

        // Line 2: 100,000 - 5,000 disc = 95,000 DPP, 10,450 PPN
        CustomerInvoiceLine::create([
            'tenant_id' => $this->tenant->id,
            'customer_invoice_id' => $multiInvoice->id,
            'line_number' => 2,
            'product_id' => $this->product->id,
            'description' => 'Line 2 Item',
            'quantity' => 1.0000,
            'unit_price' => 100000.00,
            'discount_amount' => 5000.00,
            'tax_amount' => 10450.00,
            'line_total' => 105450.00,
        ]);

        $docMulti = $this->gateway->buildDocumentFromCustomerInvoice($multiInvoice);
        $this->assertSame('285000.00', $docMulti->dppAmount);
        $this->assertSame('31350.00', $docMulti->ppnAmount);
        $this->validator->assertValid($docMulti);

        $xml = $this->gateway->generateXml($docMulti);
        $this->assertStringContainsString('285000', $xml);
        $this->assertStringContainsString('31350', $xml);
    }
}
