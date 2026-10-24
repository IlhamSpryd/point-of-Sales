<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Core\Context\TenantContext;
use App\Enums\SupplierInvoiceStatusEnum;
use App\Enums\SupplierStatusEnum;
use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Purchasing\Exceptions\OverReceiptToleranceExceededException;
use App\Modules\Purchasing\Models\AccountingPayableOutbox;
use App\Modules\Purchasing\Models\PurchaseDebitNote;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\GoodsReceivedNoteService;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\PurchaseReturnService;
use App\Modules\Purchasing\Services\SupplierInvoiceMatchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PurchasingConcurrencyAndIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private User $purchasingUser;

    private User $approverUser;

    private Supplier $supplier;

    private Product $product;

    private PurchaseOrderService $poService;

    private GoodsReceivedNoteService $grnService;

    private SupplierInvoiceMatchingService $invoiceService;

    private PurchaseReturnService $returnService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = Tenant::create([
            'name' => 'Resto Concurrency Group',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'status' => 'active',
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cabang Concurrency Pusat',
            'status' => 'active',
        ]);

        app(TenantContext::class)->setStoreId($this->store->id);

        $role = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Purchasing Admin',
            'permissions' => ['*'],
            'is_admin' => true,
        ]);

        $this->purchasingUser = User::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
            'name' => 'Staff Purchasing',
            'email' => 'purchasing@concurrency.test',
            'password' => bcrypt('Secret123!'),
        ]);
        $this->purchasingUser->stores()->attach($this->store->id, ['tenant_id' => $this->tenant->id]);

        $this->approverUser = User::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
            'name' => 'Manager Purchasing',
            'email' => 'manager@concurrency.test',
            'password' => bcrypt('Secret123!'),
        ]);
        $this->approverUser->stores()->attach($this->store->id, ['tenant_id' => $this->tenant->id]);

        $this->supplier = Supplier::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'SUP-CONCUR-01',
            'name' => 'PT Distribusi Logistik Amanah',
            'currency_code' => 'IDR',
            'status' => SupplierStatusEnum::Active,
        ]);

        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Bahan Baku Uji',
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'name' => 'Gula Pasir Kristal 1kg',
            'sku' => 'SUGAR-001',
            'price' => 15000,
            'cost_price' => 12000,
            'is_active' => true,
        ]);

        $this->poService = app(PurchaseOrderService::class);
        $this->grnService = app(GoodsReceivedNoteService::class);
        $this->invoiceService = app(SupplierInvoiceMatchingService::class);
        $this->returnService = app(PurchaseReturnService::class);
    }

    public function test_purchase_order_idempotency_key_prevents_duplicate_creation(): void
    {
        $idempotencyKey = 'po-idemp-key-unique-001';

        $headerData = [
            'store_id' => $this->store->id,
            'supplier_id' => $this->supplier->id,
            'order_date' => now()->toDateString(),
            'expected_delivery_date' => now()->addDays(5)->toDateString(),
        ];

        $linesData = [
            [
                'purchasable_type' => 'product',
                'purchasable_id' => $this->product->id,
                'purchase_uom' => 'KG',
                'uom_conversion_factor' => '1.000000',
                'ordered_qty' => '10.0000',
                'unit_price' => '12000.0000',
            ],
        ];

        // 1. Pembuatan pertama
        $po1 = $this->poService->createDirectPurchaseOrder(
            $headerData,
            $linesData,
            $this->purchasingUser,
            $idempotencyKey
        );

        $this->assertGreaterThan(0, $po1->id);
        $this->assertSame($idempotencyKey, $po1->idempotency_key);

        // 2. Pemanggilan ulang dengan idempotency key yang sama harus mengembalikan instance yang sama dan TIDAK menggandakan PO
        $po2 = $this->poService->createDirectPurchaseOrder(
            $headerData,
            $linesData,
            $this->purchasingUser,
            $idempotencyKey
        );

        $this->assertSame($po1->id, $po2->id);
        $this->assertSame(1, PurchaseOrder::query()->where('idempotency_key', $idempotencyKey)->count());
    }

    public function test_grn_posting_cannot_exceed_po_ordered_qty_beyond_tolerance_under_parallel_receiving(): void
    {
        // 1. Buat PO untuk 10 unit dengan toleransi over-receipt 0% (ketat)
        $po = $this->poService->createDirectPurchaseOrder(
            [
                'store_id' => $this->store->id,
                'supplier_id' => $this->supplier->id,
                'order_date' => now()->toDateString(),
                'expected_delivery_date' => now()->addDays(5)->toDateString(),
            ],
            [
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $this->product->id,
                    'purchase_uom' => 'KG',
                    'uom_conversion_factor' => '1.000000',
                    'ordered_qty' => '10.0000',
                    'unit_price' => '12000.0000',
                    'over_receipt_tolerance_pct' => '0.00',
                ],
            ],
            $this->purchasingUser
        );
        $po = $this->poService->submitPurchaseOrder($po, $this->purchasingUser);
        $po = $this->poService->releasePurchaseOrder($po, $this->purchasingUser);

        $poLine = $po->lines->first();

        // 2. Buat GRN-1 untuk 6 unit
        $grn1 = $this->grnService->createGoodsReceivedNote(
            po: $po,
            headerData: ['received_at' => now()->toDateTimeString()],
            linesData: [
                [
                    'purchase_order_line_id' => $poLine->id,
                    'received_qty' => '6.0000',
                    'accepted_qty' => '6.0000',
                    'rejected_qty' => '0.0000',
                ],
            ],
            actor: $this->purchasingUser
        );
        $this->grnService->postGrn($grn1, $this->purchasingUser);

        // 3. Buat GRN-2 yang mencoba menerima 5 unit (total 6 + 5 = 11 > 10, melampaui toleransi 0%)
        $this->expectException(OverReceiptToleranceExceededException::class);

        $this->grnService->createGoodsReceivedNote(
            po: $po->fresh(),
            headerData: ['received_at' => now()->toDateTimeString()],
            linesData: [
                [
                    'purchase_order_line_id' => $poLine->id,
                    'received_qty' => '5.0000',
                    'accepted_qty' => '5.0000',
                    'rejected_qty' => '0.0000',
                ],
            ],
            actor: $this->purchasingUser
        );
    }

    public function test_grn_posting_idempotency_key_prevents_duplicate_stock_movements(): void
    {
        $po = $this->poService->createDirectPurchaseOrder(
            [
                'store_id' => $this->store->id,
                'supplier_id' => $this->supplier->id,
                'order_date' => now()->toDateString(),
                'expected_delivery_date' => now()->addDays(5)->toDateString(),
            ],
            [
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $this->product->id,
                    'purchase_uom' => 'KG',
                    'uom_conversion_factor' => '1.000000',
                    'ordered_qty' => '10.0000',
                    'unit_price' => '12000.0000',
                ],
            ],
            $this->purchasingUser
        );
        $po = $this->poService->submitPurchaseOrder($po, $this->purchasingUser);
        $po = $this->poService->releasePurchaseOrder($po, $this->purchasingUser);

        $grn = $this->grnService->createGoodsReceivedNote(
            po: $po,
            headerData: ['received_at' => now()->toDateTimeString()],
            linesData: [
                [
                    'purchase_order_line_id' => $po->lines->first()->id,
                    'received_qty' => '10.0000',
                    'accepted_qty' => '10.0000',
                    'rejected_qty' => '0.0000',
                ],
            ],
            actor: $this->purchasingUser
        );

        $grnIdempotencyKey = 'grn-post-idemp-001';

        $postedGrn1 = $this->grnService->postGrn($grn, $this->purchasingUser, $grnIdempotencyKey);
        $this->assertTrue($postedGrn1->isPosted());
        $initialMovementsCount = StockMovement::query()->where('store_id', $this->store->id)->count();

        // 1. Pemanggilan ulang dengan idempotency key yang sama harus idempoten (mengembalikan instance yang sama tanpa mutasi stok ganda)
        $postedGrnAgain = $this->grnService->postGrn($postedGrn1, $this->purchasingUser, $grnIdempotencyKey);
        $this->assertSame($postedGrn1->id, $postedGrnAgain->id);
        $this->assertSame($initialMovementsCount, StockMovement::query()->where('store_id', $this->store->id)->count());

        // 2. Percobaan posting ulang dengan idempotency key berbeda ditolak oleh status machine
        $this->expectException(\DomainException::class);
        $this->grnService->postGrn($postedGrn1, $this->purchasingUser, 'grn-diff-idemp-key');
    }

    public function test_supplier_invoice_rejects_duplicate_invoice_number_and_idempotency_key_prevents_duplicate_outbox(): void
    {
        $po = $this->poService->createDirectPurchaseOrder(
            [
                'store_id' => $this->store->id,
                'supplier_id' => $this->supplier->id,
                'order_date' => now()->toDateString(),
                'expected_delivery_date' => now()->addDays(5)->toDateString(),
            ],
            [
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $this->product->id,
                    'purchase_uom' => 'KG',
                    'uom_conversion_factor' => '1.000000',
                    'ordered_qty' => '10.0000',
                    'unit_price' => '12000.0000',
                ],
            ],
            $this->purchasingUser
        );
        $po = $this->poService->submitPurchaseOrder($po, $this->purchasingUser);
        $po = $this->poService->releasePurchaseOrder($po, $this->purchasingUser);

        $grn = $this->grnService->createGoodsReceivedNote(
            po: $po,
            headerData: ['received_at' => now()->toDateTimeString()],
            linesData: [
                [
                    'purchase_order_line_id' => $po->lines->first()->id,
                    'received_qty' => '10.0000',
                    'accepted_qty' => '10.0000',
                    'rejected_qty' => '0.0000',
                ],
            ],
            actor: $this->purchasingUser
        );
        $postedGrn = $this->grnService->postGrn($grn, $this->purchasingUser);

        // 1. Buat Faktur Pemasok pertama
        $invHeader = [
            'store_id' => $this->store->id,
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $po->id,
            'supplier_invoice_number' => 'INV-CONCUR-999',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ];

        $invLines = [
            [
                'purchasable_type' => 'product',
                'purchasable_id' => $this->product->id,
                'purchase_order_line_id' => $po->lines->first()->id,
                'invoiced_qty' => '10.0000',
                'unit_price' => '12000.0000',
            ],
        ];

        $invoice1 = $this->invoiceService->createSupplierInvoice(
            $invHeader,
            $invLines,
            $this->purchasingUser
        );
        $this->assertGreaterThan(0, $invoice1->id);

        // Lakukan 3-way matching dengan GRN yang telah diposting
        $matchedInvoice = $this->invoiceService->performThreeWayMatching(
            invoice: $invoice1,
            matchesPlan: [
                [
                    'supplier_invoice_line_id' => $invoice1->lines->first()->id,
                    'goods_received_note_line_id' => $postedGrn->lines->first()->id,
                    'matched_qty' => '10.0000',
                ],
            ],
            actor: $this->purchasingUser
        );

        // Approve payable - menerbitkan event ke AccountingPayableOutbox
        $approvedInvoice = $this->invoiceService->approvePayable($matchedInvoice, $this->approverUser);
        $this->assertSame(SupplierInvoiceStatusEnum::ApprovedPayable, $approvedInvoice->status);

        $outboxCount = AccountingPayableOutbox::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('source_document_id', $approvedInvoice->id)
            ->count();
        $this->assertSame(1, $outboxCount);

        // Percobaan approve ulang ditolak oleh validasi status
        try {
            $this->invoiceService->approvePayable($approvedInvoice, $this->approverUser);
            $this->fail('Expected DomainException for duplicate approval.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('status', $e->getMessage());
        }

        // Tetap hanya 1 outbox record (idempotency terjamin, tidak ada event ganda)
        $this->assertSame(1, AccountingPayableOutbox::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('source_document_id', $approvedInvoice->id)
            ->count());

        // 2. Pembuatan faktur kedua dengan nomor invoice yang sama untuk pemasok yang sama HARUS DITOLAK
        $this->expectException(ValidationException::class);
        $this->invoiceService->createSupplierInvoice(
            $invHeader,
            $invLines,
            $this->purchasingUser
        );
    }

    public function test_purchase_return_idempotency_prevents_duplicate_stock_deduction(): void
    {
        // 1. Siapkan PO & GRN
        $po = $this->poService->createDirectPurchaseOrder(
            [
                'store_id' => $this->store->id,
                'supplier_id' => $this->supplier->id,
                'order_date' => now()->toDateString(),
                'expected_delivery_date' => now()->addDays(5)->toDateString(),
            ],
            [
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $this->product->id,
                    'purchase_uom' => 'KG',
                    'uom_conversion_factor' => '1.000000',
                    'ordered_qty' => '10.0000',
                    'unit_price' => '12000.0000',
                ],
            ],
            $this->purchasingUser
        );
        $po = $this->poService->submitPurchaseOrder($po, $this->purchasingUser);
        $po = $this->poService->releasePurchaseOrder($po, $this->purchasingUser);

        $grn = $this->grnService->createGoodsReceivedNote(
            po: $po,
            headerData: ['received_at' => now()->toDateTimeString()],
            linesData: [
                [
                    'purchase_order_line_id' => $po->lines->first()->id,
                    'received_qty' => '10.0000',
                    'accepted_qty' => '10.0000',
                    'rejected_qty' => '0.0000',
                ],
            ],
            actor: $this->purchasingUser
        );
        $postedGrn = $this->grnService->postGrn($grn, $this->purchasingUser);

        // Pastikan saldo stok fisik ada
        DB::table('product_stock_balances')
            ->where('tenant_id', $this->tenant->id)
            ->where('store_id', $this->store->id)
            ->where('product_id', $this->product->id)
            ->update(['quantity' => '10.0000']);

        // 2. Buat retur pembelian
        $retHeader = [
            'store_id' => $this->store->id,
            'supplier_id' => $this->supplier->id,
            'goods_received_note_id' => $postedGrn->id,
            'return_date' => now()->toDateString(),
            'reason' => 'Barang rusak dalam kemasan',
        ];

        $retLines = [
            [
                'goods_received_note_line_id' => $postedGrn->lines->first()->id,
                'return_qty' => '3.0000',
                'reason' => 'Bocor',
            ],
        ];

        $return = $this->returnService->createPurchaseReturn(
            $retHeader,
            $retLines,
            $this->purchasingUser
        );

        $result = $this->returnService->postPurchaseReturn(
            $return->id,
            $this->purchasingUser,
            'ret-post-idemp-01'
        );

        $postedReturn = $result['purchase_return'];
        $this->assertTrue($postedReturn->isPosted());
        $this->assertSame(1, PurchaseDebitNote::query()->where('purchase_return_id', $postedReturn->id)->count());

        // 1. Pemanggilan ulang dengan idempotency key yang sama mengembalikan hasil yang sama dan tidak menggandakan debit note
        $resultAgain = $this->returnService->postPurchaseReturn(
            $postedReturn->id,
            $this->purchasingUser,
            'ret-post-idemp-01'
        );
        $this->assertSame($postedReturn->id, $resultAgain['purchase_return']->id);
        $this->assertSame(1, PurchaseDebitNote::query()->where('purchase_return_id', $postedReturn->id)->count());

        // 2. Percobaan posting ulang retur yang sudah diposting dengan key berbeda ditolak
        $this->expectException(ValidationException::class);
        $this->returnService->postPurchaseReturn(
            $postedReturn->id,
            $this->purchasingUser,
            'ret-post-diff-key'
        );
    }
}
