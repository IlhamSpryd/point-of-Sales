<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Core\Context\TenantContext;
use App\Enums\CostAllocationMethodEnum;
use App\Enums\InvoiceMatchingStatusEnum;
use App\Enums\PurchaseCostCategoryEnum;
use App\Enums\PurchaseCostStatusEnum;
use App\Enums\SupplierInvoiceStatusEnum;
use App\Enums\SupplierStatusEnum;
use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Purchasing\Models\GoodsReceivedNoteLine;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\GoodsReceivedNoteService;
use App\Modules\Purchasing\Services\LandedCostService;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\SupplierInvoiceMatchingService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Wave 5 Purchasing & Supplier Management Feature Tests
 *
 * Menguji kepatuhan kriteria penerimaan Gelombang 5:
 * - T-PUR-01: GRN otomatis menambah stok di product_stock_balances dan mencatat mutasi di stock_movements.
 * - T-PUR-02: Verifikasi 3-Arah mendeteksi selisih harga 10%, menahan faktur ke status VARIANCE_HOLD / DISCREPANT, dan memblokir pencatatan hutang usaha.
 * - T-PUR-03: Alokasi Landed Cost membagi biaya kirim Rp 100.000 secara proporsional ke 2 produk dengan rasio 1:3 (Rp 25.000 dan Rp 75.000).
 */
class Wave5PurchasingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private User $ownerUser;

    private User $accountantUser;

    private Supplier $supplier;

    private PurchaseOrderService $poService;

    private GoodsReceivedNoteService $grnService;

    private SupplierInvoiceMatchingService $invoiceService;

    private LandedCostService $landedCostService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = Tenant::create([
            'name' => 'Resto Wave 5 Pur Corp',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'status' => 'active',
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cabang Sentra Pengadaan',
            'status' => 'active',
        ]);

        app(TenantContext::class)->setStoreId($this->store->id);

        $ownerRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Owner',
            'is_admin' => true,
            'permissions' => ['*'],
        ]);

        $accountantRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Staff Accounting',
            'is_admin' => false,
            'permissions' => [
                'purchasing.invoice.view',
                'purchasing.invoice.create',
                'purchasing.invoice.match',
                'purchasing.invoice.approve_payable',
            ],
        ]);

        $this->ownerUser = User::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'role_id' => $ownerRole->id,
            'name' => 'Budi Direktur',
            'email' => 'budi.pur@wave5.test',
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);

        $this->accountantUser = User::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'role_id' => $accountantRole->id,
            'name' => 'Citra Akuntan',
            'email' => 'citra.acct@wave5.test',
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);

        $this->ownerUser->stores()->attach($this->store->id, ['tenant_id' => $this->tenant->id]);
        $this->accountantUser->stores()->attach($this->store->id, ['tenant_id' => $this->tenant->id]);

        $this->supplier = Supplier::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'SUP-W5-01',
            'name' => 'PT Mitra Pasokan Prima',
            'status' => SupplierStatusEnum::Active,
            'payment_terms_days' => 30,
            'currency_code' => 'IDR',
        ]);

        $this->poService = app(PurchaseOrderService::class);
        $this->grnService = app(GoodsReceivedNoteService::class);
        $this->invoiceService = app(SupplierInvoiceMatchingService::class);
        $this->landedCostService = app(LandedCostService::class);
    }

    /**
     * T-PUR-01: Pembuatan GRN otomatis menambah kuantitas saldo fisik di product_stock_balances
     * dan mencatat entri mutasi penerimaan barang di stock_movements.
     */
    public function test_t_pur_01_grn_creation_increments_physical_stock_and_records_goods_receipt_movement(): void
    {
        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Bahan Baku Segar',
            'slug' => 'bahan-baku-segar',
        ]);

        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'name' => 'Beras Pandan Wangi 5kg',
            'sku' => 'PRD-BERAS-5K',
            'price' => '75000.00',
            'stock' => 0,
        ]);

        // Buat PO untuk 20 sak beras @ Rp 60.000
        $po = $this->poService->createDirectPurchaseOrder(
            [
                'store_id' => $this->store->id,
                'supplier_id' => $this->supplier->id,
                'order_date' => now()->toDateString(),
            ],
            [
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $product->id,
                    'purchase_uom' => 'SAK',
                    'uom_conversion_factor' => '1.000000',
                    'ordered_qty' => '20.0000',
                    'unit_price' => '60000.0000',
                ],
            ],
            $this->ownerUser
        );

        $po = $this->poService->submitPurchaseOrder($po, $this->ownerUser);
        $po = $this->poService->releasePurchaseOrder($po, $this->ownerUser);
        $poLine = $po->lines()->firstOrFail();

        // Buat dan posting GRN untuk 20 sak
        $grn = $this->grnService->createGoodsReceivedNote(
            $po,
            ['supplier_delivery_note_no' => 'SJ-BERAS-001'],
            [
                [
                    'purchase_order_line_id' => $poLine->id,
                    'received_qty' => '20.0000',
                    'accepted_qty' => '20.0000',
                    'rejected_qty' => '0.0000',
                ],
            ],
            $this->ownerUser
        );

        $postedGrn = $this->grnService->postGrn($grn, $this->ownerUser);
        $grnLine = $postedGrn->lines()->firstOrFail();

        // 1. Verifikasi stock_movement_id terisi
        $this->assertNotNull($grnLine->stock_movement_id);

        // 2. Verifikasi record di stock_movements
        $movement = DB::table('stock_movements')
            ->where('id', $grnLine->stock_movement_id)
            ->first();

        $this->assertNotNull($movement);
        $this->assertEquals($this->tenant->id, $movement->tenant_id);
        $this->assertEquals($this->store->id, $movement->store_id);
        $this->assertEquals($product->id, $movement->product_id);
        $this->assertContains(strtolower((string) $movement->type), ['goods_receipt', 'purchase_receipt']);
        $this->assertEquals(20, (int) $movement->quantity);
        $this->assertEquals('60000.0000', $movement->unit_cost);

        // 3. Verifikasi saldo fisik di product_stock_balances
        $balance = DB::table('product_stock_balances')
            ->where('tenant_id', $this->tenant->id)
            ->where('store_id', $this->store->id)
            ->where('product_id', $product->id)
            ->first();

        $this->assertNotNull($balance);
        $this->assertEquals('20.000000', (string) $balance->quantity);
        $this->assertEquals('60000.0000', (string) $balance->average_unit_cost);
        $this->assertEquals('60000.0000', (string) $balance->last_unit_cost);

        // 4. Verifikasi kolom mirror legacy products.stock
        $product->refresh();
        $this->assertEquals(20, (int) $product->stock);
    }

    /**
     * T-PUR-02: Pengujian verifikasi 3-Arah membuktikan faktur dengan harga satuan lebih mahal 10% dari PO
     * otomatis tertahan dengan status DISCREPANT / VARIANCE_HOLD dan memblokir pencatatan jurnal hutang usaha.
     */
    public function test_t_pur_02_three_way_matching_marks_discrepant_invoice_and_blocks_ap_posting(): void
    {
        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Daging Olahan',
            'slug' => 'daging-olahan',
        ]);

        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'name' => 'Sosis Sapi Premium',
            'sku' => 'PRD-SOSIS-01',
            'price' => '100000.00',
        ]);

        // PO dengan unit_price Rp 100.000 untuk 10 pack
        $po = $this->poService->createDirectPurchaseOrder(
            [
                'store_id' => $this->store->id,
                'supplier_id' => $this->supplier->id,
                'order_date' => now()->toDateString(),
            ],
            [
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $product->id,
                    'purchase_uom' => 'PACK',
                    'uom_conversion_factor' => '1.000000',
                    'ordered_qty' => '10.0000',
                    'unit_price' => '100000.0000',
                ],
            ],
            $this->ownerUser
        );

        $po = $this->poService->submitPurchaseOrder($po, $this->ownerUser);
        $po = $this->poService->releasePurchaseOrder($po, $this->ownerUser);
        $poLine = $po->lines()->firstOrFail();

        // GRN 10 pack diterima penuh
        $grn = $this->grnService->createGoodsReceivedNote(
            $po,
            ['supplier_delivery_note_no' => 'SJ-SOSIS-101'],
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
        $postedGrn = $this->grnService->postGrn($grn, $this->ownerUser);
        $grnLine = $postedGrn->lines()->firstOrFail();

        // Supplier menerbitkan tagihan dengan harga satuan Rp 110.000 (10% lebih mahal dari PO Rp 100.000)
        $invoice = $this->invoiceService->createSupplierInvoice(
            [
                'store_id' => $this->store->id,
                'supplier_id' => $this->supplier->id,
                'purchase_order_id' => $po->id,
                'supplier_invoice_number' => 'INV-DISCREPANT-10PCT',
                'invoice_date' => now()->toDateString(),
                'due_date' => now()->addDays(14)->toDateString(),
            ],
            [
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $product->id,
                    'purchase_order_line_id' => $poLine->id,
                    'invoiced_qty' => '10.0000',
                    'unit_price' => '110000.0000', // Kenaikan 10%
                ],
            ],
            $this->accountantUser
        );

        $invLine = $invoice->lines()->firstOrFail();

        // Lakukan verifikasi 3-Arah (Three-Way Matching)
        $matchedInvoice = $this->invoiceService->performThreeWayMatching(
            $invoice,
            [
                [
                    'supplier_invoice_line_id' => $invLine->id,
                    'goods_received_note_line_id' => $grnLine->id,
                    'matched_qty' => '10.0000',
                ],
            ],
            $this->accountantUser
        );

        // Asersi status tertahan selisih (VarianceHold / VarianceDetected)
        $this->assertEquals(SupplierInvoiceStatusEnum::VarianceHold, $matchedInvoice->status);
        $this->assertEquals(InvoiceMatchingStatusEnum::VarianceDetected, $matchedInvoice->matching_status);
        $this->assertNotNull($matchedInvoice->hold_reason);

        // Asersi pemblokiran pencatatan hutang usaha: approvePayable harus melempar DomainException
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Variance Hold');
        $this->invoiceService->approvePayable($matchedInvoice, $this->accountantUser);
    }

    /**
     * T-PUR-03: Pengujian alokasi Landed Cost membuktikan biaya kirim Rp 100.000 terbagi secara proporsional
     * ke 2 produk dengan rasio nilai belanja 1:3 (Rp 25.000 dan Rp 75.000).
     */
    public function test_t_pur_03_landed_cost_allocation_divides_freight_cost_proportionally_by_value(): void
    {
        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Bahan Baku Import',
            'slug' => 'bahan-baku-import',
        ]);

        // Produk 1: nilai belanja Rp 50.000 (1 unit @ Rp 50.000) -> bobot 1
        $product1 = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'name' => 'Bumbu Rempah A (Rasio 1)',
            'sku' => 'PRD-BUMBU-A',
            'price' => '60000.00',
        ]);

        // Produk 2: nilai belanja Rp 150.000 (1 unit @ Rp 150.000) -> bobot 3
        $product2 = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'name' => 'Saus Impor B (Rasio 3)',
            'sku' => 'PRD-SAUS-B',
            'price' => '180000.00',
        ]);

        // PO dengan nilai belanja Produk 1 (Rp 50.000) dan Produk 2 (Rp 150.000) -> Total Rp 200.000 (Rasio 1:3)
        $po = $this->poService->createDirectPurchaseOrder(
            [
                'store_id' => $this->store->id,
                'supplier_id' => $this->supplier->id,
                'order_date' => now()->toDateString(),
            ],
            [
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $product1->id,
                    'purchase_uom' => 'PCS',
                    'uom_conversion_factor' => '1.000000',
                    'ordered_qty' => '1.0000',
                    'unit_price' => '50000.0000',
                ],
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $product2->id,
                    'purchase_uom' => 'PCS',
                    'uom_conversion_factor' => '1.000000',
                    'ordered_qty' => '1.0000',
                    'unit_price' => '150000.0000',
                ],
            ],
            $this->ownerUser
        );

        $po = $this->poService->submitPurchaseOrder($po, $this->ownerUser);
        $po = $this->poService->releasePurchaseOrder($po, $this->ownerUser);

        $poLines = $po->lines()->orderBy('id', 'asc')->get();
        /** @var PurchaseOrderLine $poLine1 */
        $poLine1 = $poLines->get(0);
        /** @var PurchaseOrderLine $poLine2 */
        $poLine2 = $poLines->get(1);
        $this->assertInstanceOf(PurchaseOrderLine::class, $poLine1);
        $this->assertInstanceOf(PurchaseOrderLine::class, $poLine2);

        // Buat & posting GRN
        $grn = $this->grnService->createGoodsReceivedNote(
            $po,
            ['supplier_delivery_note_no' => 'SJ-LANDED-001'],
            [
                [
                    'purchase_order_line_id' => $poLine1->id,
                    'received_qty' => '1.0000',
                    'accepted_qty' => '1.0000',
                    'rejected_qty' => '0.0000',
                ],
                [
                    'purchase_order_line_id' => $poLine2->id,
                    'received_qty' => '1.0000',
                    'accepted_qty' => '1.0000',
                    'rejected_qty' => '0.0000',
                ],
            ],
            $this->ownerUser
        );

        $postedGrn = $this->grnService->postGrn($grn, $this->ownerUser);
        $grnLines = $postedGrn->lines()->orderBy('id', 'asc')->get();
        /** @var GoodsReceivedNoteLine $grnLine1 */
        $grnLine1 = $grnLines->get(0);
        /** @var GoodsReceivedNoteLine $grnLine2 */
        $grnLine2 = $grnLines->get(1);
        $this->assertInstanceOf(GoodsReceivedNoteLine::class, $grnLine1);
        $this->assertInstanceOf(GoodsReceivedNoteLine::class, $grnLine2);

        // Catat biaya kirim tambahan (Freight) Rp 100.000
        $freightCost = $this->landedCostService->recordAdditionalCost(
            [
                'store_id' => $this->store->id,
                'goods_received_note_id' => $postedGrn->id,
                'category' => PurchaseCostCategoryEnum::Freight,
                'description' => 'Ongkos Kirim Ekspedisi Logistik',
                'amount' => '100000.00',
                'allocation_method' => CostAllocationMethodEnum::ByValue,
            ],
            $this->ownerUser
        );

        $this->assertEquals(PurchaseCostStatusEnum::Draft, $freightCost->status);

        // Jalankan alokasi landed cost proporsional berdasarkan nilai (BY_VALUE)
        $allocatedCost = $this->landedCostService->allocateLandedCost(
            costId: $freightCost->id,
            grnLineIds: [$grnLine1->id, $grnLine2->id],
            method: CostAllocationMethodEnum::ByValue,
            actor: $this->ownerUser
        );

        $this->assertEquals(PurchaseCostStatusEnum::Allocated, $allocatedCost->status);

        // Ambil hasil alokasi
        $allocations = $allocatedCost->allocations()->orderBy('goods_received_note_line_id', 'asc')->get();
        $this->assertCount(2, $allocations);

        $alloc1 = $allocations->where('goods_received_note_line_id', $grnLine1->id)->first();
        $alloc2 = $allocations->where('goods_received_note_line_id', $grnLine2->id)->first();

        $this->assertNotNull($alloc1);
        $this->assertNotNull($alloc2);

        // Produk 1 (Rasio 1 dari 4): Rp 25.000,00
        $this->assertEquals('25000.00', (string) $alloc1->allocated_amount);

        // Produk 2 (Rasio 3 dari 4): Rp 75.000,00
        $this->assertEquals('75000.00', (string) $alloc2->allocated_amount);

        // Invarian Konservasi Total: Rp 25.000 + Rp 75.000 === Rp 100.000
        $amount1 = is_numeric($alloc1->allocated_amount) ? (string) $alloc1->allocated_amount : '0.00';
        $amount2 = is_numeric($alloc2->allocated_amount) ? (string) $alloc2->allocated_amount : '0.00';
        $totalAllocated = bcadd($amount1, $amount2, 2);
        $this->assertEquals('100000.00', $totalAllocated);

        // Periksa effective_unit_cost pada baris GRN
        $grnLine1->refresh();
        $grnLine2->refresh();

        // Line 1: Rp 50.000 snapshot + Rp 25.000 landed cost = Rp 75.000
        $this->assertEquals('25000.0000', (string) $grnLine1->allocated_landed_cost_per_unit);
        $this->assertEquals('75000.0000', (string) $grnLine1->effective_unit_cost);

        // Line 2: Rp 150.000 snapshot + Rp 75.000 landed cost = Rp 225.000
        $this->assertEquals('75000.0000', (string) $grnLine2->allocated_landed_cost_per_unit);
        $this->assertEquals('225000.0000', (string) $grnLine2->effective_unit_cost);
    }
}
