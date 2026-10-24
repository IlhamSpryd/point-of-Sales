<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Core\Context\TenantContext;
use App\Enums\SupplierStatusEnum;
use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Purchasing\Models\AccountingPayableOutbox;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\GoodsReceivedNoteService;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\SupplierInvoiceMatchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class M7AccountingBoundaryContractTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private User $ownerUser;

    private Supplier $supplier;

    private Product $product;

    private PurchaseOrderService $poService;

    private GoodsReceivedNoteService $grnService;

    private SupplierInvoiceMatchingService $invoiceService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = Tenant::create([
            'name' => 'Resto Berkah Abadi',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'status' => 'active',
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cabang Utama Jakarta',
            'status' => 'active',
        ]);

        app(TenantContext::class)->setStoreId($this->store->id);

        $ownerRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Owner',
            'is_admin' => true,
        ]);

        $this->ownerUser = User::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'role_id' => $ownerRole->id,
            'name' => 'Pak Budi Pemilik',
            'email' => 'owner.berkah@test.com',
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);

        $this->ownerUser->stores()->attach($this->store->id, ['tenant_id' => $this->tenant->id]);

        $this->supplier = Supplier::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'SUP-BERKAH-01',
            'name' => 'PT Pangan Sumber Makmur',
            'status' => SupplierStatusEnum::Active,
            'payment_terms_days' => 14,
            'currency_code' => 'IDR',
        ]);

        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Bahan Baku',
            'slug' => 'bahan-baku',
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'name' => 'Beras Pandan Wangi 5kg',
            'sku' => 'PRD-BERAS-5K',
            'price' => '85000.00',
        ]);

        $this->poService = app(PurchaseOrderService::class);
        $this->grnService = app(GoodsReceivedNoteService::class);
        $this->invoiceService = app(SupplierInvoiceMatchingService::class);
    }

    /**
     * AT-17: Persetujuan Faktur Pemasok menjadi approved_payable menerbitkan
     * rekaman kontrak outbox M5 -> M7 yang valid, lengkap, dan berversi ("1.0").
     */
    public function test_approved_payable_invoice_publishes_valid_m7_outbox_contract(): void
    {
        // 1. PO & GRN
        $po = $this->poService->createDirectPurchaseOrder(
            [
                'store_id' => $this->store->id,
                'supplier_id' => $this->supplier->id,
                'order_date' => now()->toDateString(),
            ],
            [
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $this->product->id,
                    'purchase_uom' => 'BAG',
                    'ordered_qty' => '10.0000',
                    'unit_price' => '80000.0000',
                ],
            ],
            $this->ownerUser
        );

        $po = $this->poService->submitPurchaseOrder($po, $this->ownerUser);
        $po = $this->poService->releasePurchaseOrder($po, $this->ownerUser);
        $poLine = $po->lines()->firstOrFail();

        $grn = $this->grnService->createGoodsReceivedNote(
            $po,
            ['supplier_delivery_note_no' => 'SJ-CONTRACT-001'],
            [
                [
                    'purchase_order_line_id' => $poLine->id,
                    'received_qty' => '10.0000',
                    'accepted_qty' => '10.0000',
                    'rejected_qty' => '0.0000',
                ],
            ],
            $this->ownerUser
        );
        $this->grnService->postGrn($grn, $this->ownerUser);

        // 2. Buat Faktur Pemasok
        $invoice = $this->invoiceService->createSupplierInvoice(
            [
                'store_id' => $this->store->id,
                'supplier_id' => $this->supplier->id,
                'purchase_order_id' => $po->id,
                'supplier_invoice_number' => 'INV-M7-CONTRACT-001',
                'invoice_date' => now()->toDateString(),
                'due_date' => now()->addDays(14)->toDateString(),
                'currency_code' => 'IDR',
                'additional_charges_amount' => '50000.00',
            ],
            [
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $this->product->id,
                    'purchase_order_line_id' => $poLine->id,
                    'invoiced_qty' => '10.0000',
                    'unit_price' => '80000.0000',
                    'discount_amount' => '20000.00',
                    'tax_rate' => '11.0000',
                ],
            ],
            $this->ownerUser
        );

        $invLine = $invoice->lines()->firstOrFail();
        $grnLine = $grn->lines()->firstOrFail();

        // 3. Cocokkan 3-Way Match
        $matchedInvoice = $this->invoiceService->performThreeWayMatching(
            $invoice,
            [
                [
                    'supplier_invoice_line_id' => $invLine->id,
                    'goods_received_note_line_id' => $grnLine->id,
                    'matched_qty' => '10.0000',
                ],
            ],
            $this->ownerUser
        );

        // 4. Setujui menjadi kewajiban siap bayar (Approved Payable)
        $approvedInvoice = $this->invoiceService->approvePayable($matchedInvoice, $this->ownerUser);

        // 5. Verifikasi Kontrak Outbox M5 -> M7 di tabel accounting_payable_outbox
        $outboxRecord = AccountingPayableOutbox::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('source_document_id', $approvedInvoice->id)
            ->where('event_type', 'purchasing.invoice.approved_payable')
            ->first();

        $this->assertNotNull($outboxRecord, 'Rekaman kontrak outbox M7 wajib diterbitkan saat invoice approved_payable.');
        $this->assertEquals('pending', $outboxRecord->status);
        $this->assertEquals("invoice:payable:{$this->tenant->id}:{$approvedInvoice->id}", $outboxRecord->idempotency_key);
        $this->assertEquals($this->store->id, $outboxRecord->store_id);
        $this->assertEquals('supplier_invoice', $outboxRecord->source_document_type);
        $this->assertEquals($approvedInvoice->internal_invoice_number, $outboxRecord->source_document_number);

        // 6. Validasi Payload Kontrak Berversi
        $payload = $outboxRecord->payload;
        $this->assertEquals('1.0', $payload['schema_version']);
        $this->assertEquals($outboxRecord->event_id, $payload['event_id']);
        $this->assertEquals($this->tenant->id, $payload['tenant_id']);
        $this->assertEquals($this->supplier->id, $payload['supplier_id']);
        $this->assertEquals($approvedInvoice->supplier_invoice_number, $payload['supplier_invoice_number']);
        $this->assertEquals($approvedInvoice->total_amount, $payload['total_amount']);
        $this->assertCount(1, $payload['line_items']);

        $linePayload = $payload['line_items'][0];
        $this->assertEquals('product', $linePayload['purchasable_type']);
        $this->assertEquals($this->product->id, $linePayload['purchasable_id']);
        $this->assertEquals('10.0000', $linePayload['invoiced_qty']);
        $this->assertEquals('80000.0000', $linePayload['unit_price']);
    }
}
