<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Core\Context\TenantContext;
use App\Enums\ReorderSuggestionStatusEnum;
use App\Enums\SupplierStatusEnum;
use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\ReorderSuggestion;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierPerformanceLog;
use App\Modules\Purchasing\Models\SupplierProduct;
use App\Modules\Purchasing\Services\GoodsReceivedNoteService;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\PurchaseRequestService;
use App\Modules\Purchasing\Services\ReorderSuggestionService;
use App\Modules\Purchasing\Services\SupplierPerformanceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReorderSuggestionEngineTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private User $purchasingUser;

    private Supplier $supplier;

    private Product $productA;

    private Product $productB;

    private ReorderSuggestionService $reorderService;

    private SupplierPerformanceService $performanceService;

    private PurchaseRequestService $prService;

    private PurchaseOrderService $poService;

    private GoodsReceivedNoteService $grnService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = Tenant::create([
            'name' => 'Resto Reorder Group',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'status' => 'active',
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cabang Sentra Reorder',
            'status' => 'active',
        ]);

        app(TenantContext::class)->setStoreId($this->store->id);

        $role = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Purchasing Manager',
            'permissions' => ['*'],
            'is_admin' => true,
        ]);

        $this->purchasingUser = User::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
            'name' => 'Manager Purchasing',
            'email' => 'purchasing@reorder.test',
            'password' => bcrypt('Secret123!'),
        ]);

        $this->purchasingUser->stores()->attach($this->store->id, ['tenant_id' => $this->tenant->id]);

        $this->supplier = Supplier::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'SUP-REORDER-001',
            'name' => 'PT Sumber Reorder Mandiri',
            'currency_code' => 'IDR',
            'status' => SupplierStatusEnum::Active,
        ]);

        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Bahan Baku Reorder',
        ]);

        $this->productA = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'name' => 'Minyak Goreng Sawit 1L',
            'sku' => 'OIL-001',
            'price' => 25000,
            'cost_price' => 18000,
            'is_active' => true,
        ]);

        $this->productB = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'name' => 'Beras Pandan Wangi 5kg',
            'sku' => 'RICE-001',
            'price' => 75000,
            'cost_price' => 60000,
            'is_active' => true,
        ]);

        // Atur SupplierProduct untuk Product A (MOQ: 20, Multiple: 5)
        SupplierProduct::create([
            'tenant_id' => $this->tenant->id,
            'supplier_id' => $this->supplier->id,
            'purchasable_type' => 'product',
            'purchasable_id' => $this->productA->id,
            'supplier_sku' => 'SUP-OIL-1L',
            'purchase_uom' => 'BOTOL',
            'uom_conversion_factor' => '1.000000',
            'standard_price' => '18000.0000',
            'lead_time_days' => 5,
            'min_order_qty' => '20.0000',
            'order_multiple_qty' => '5.0000',
            'is_preferred' => true,
            'is_active' => true,
        ]);

        // Atur SupplierProduct untuk Product B (MOQ: 10, Multiple: 10)
        SupplierProduct::create([
            'tenant_id' => $this->tenant->id,
            'supplier_id' => $this->supplier->id,
            'purchasable_type' => 'product',
            'purchasable_id' => $this->productB->id,
            'supplier_sku' => 'SUP-RICE-5K',
            'purchase_uom' => 'SAK',
            'uom_conversion_factor' => '1.000000',
            'standard_price' => '60000.0000',
            'lead_time_days' => 3,
            'min_order_qty' => '10.0000',
            'order_multiple_qty' => '10.0000',
            'is_preferred' => true,
            'is_active' => true,
        ]);

        $this->reorderService = app(ReorderSuggestionService::class);
        $this->performanceService = app(SupplierPerformanceService::class);
        $this->prService = app(PurchaseRequestService::class);
        $this->poService = app(PurchaseOrderService::class);
        $this->grnService = app(GoodsReceivedNoteService::class);
    }

    private function updateStockBalance(Product $product, string $qty, string $min = '0.0000', string $rop = '0.0000', string $max = '0.0000'): void
    {
        StockBalance::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('store_id', $this->store->id)
            ->where('product_id', $product->id)
            ->update([
                'quantity' => $qty,
                'min_stock_level' => $min,
                'reorder_point' => $rop,
                'max_stock_level' => $max,
            ]);
    }

    public function test_net_inventory_position_calculates_on_hand_plus_open_po_plus_open_pr(): void
    {
        // 1. Set On-Hand Stock pada M4: 10.0000
        $this->updateStockBalance($this->productA, '10.0000', '15.0000', '20.0000', '50.0000');

        // 2. Buat PO Terbuka (Approved) untuk 15 unit
        $po = $this->poService->createDirectPurchaseOrder(
            [
                'store_id' => $this->store->id,
                'supplier_id' => $this->supplier->id,
                'order_date' => now()->toDateString(),
                'expected_delivery_date' => now()->addDays(7)->toDateString(),
            ],
            [
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $this->productA->id,
                    'purchase_uom' => 'BOTOL',
                    'uom_conversion_factor' => '1.000000',
                    'ordered_qty' => '15.0000',
                    'unit_price' => '18000.0000',
                ],
            ],
            $this->purchasingUser
        );
        $po = $this->poService->submitPurchaseOrder($po, $this->purchasingUser);
        $po = $this->poService->releasePurchaseOrder($po, $this->purchasingUser);

        // 3. Buat PR Terbuka (Approved) untuk 5 unit
        $pr = $this->prService->createPurchaseRequest(
            headerData: [
                'store_id' => $this->store->id,
                'required_date' => now()->addDays(7)->toDateString(),
            ],
            linesData: [
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $this->productA->id,
                    'uom' => 'BOTOL',
                    'purchase_uom' => 'BOTOL',
                    'uom_conversion_factor' => '1.000000',
                    'requested_qty' => '5.0000',
                    'estimated_unit_price' => '18000.0000',
                ],
            ],
            requester: $this->purchasingUser
        );
        $this->prService->submitPurchaseRequest($pr, $this->purchasingUser);

        // 4. Hitung Posisi Stok Bersih: 10 (On-Hand) + 15 (Open PO) + 5 (Open PR) = 30.0000
        $netData = $this->reorderService->calculateNetInventoryPosition(
            $this->tenant->id,
            $this->store->id,
            'product',
            $this->productA->id
        );

        $this->assertSame('10.0000', $netData['current_stock']);
        $this->assertSame('15.0000', $netData['on_order_po_qty']);
        $this->assertSame('5.0000', $netData['open_pr_qty']);
        $this->assertSame('30.0000', $netData['net_inventory_position']);
    }

    public function test_reorder_suggestion_does_not_trigger_when_net_position_is_above_rop(): void
    {
        // On-Hand = 25, ROP = 20 -> NetPosition = 25 > 20 -> Tidak ada saran reorder
        $this->updateStockBalance($this->productA, '25.0000', '15.0000', '20.0000', '50.0000');

        $suggestion = $this->reorderService->generateItemSuggestion(
            $this->tenant->id,
            $this->store->id,
            'product',
            $this->productA->id,
            ['reorder_point' => '20.0000']
        );

        $this->assertNull($suggestion, 'Saran reorder tidak boleh dibuat jika posisi stok bersih di atas ROP.');
    }

    public function test_reorder_suggestion_triggers_when_net_position_is_at_or_below_rop(): void
    {
        // On-Hand = 12.0000, ROP = 20.0000, MaxStock = 50.0000
        // NetPosition = 12.0000 <= 20.0000
        // Raw Deficit = 50.0000 - 12.0000 = 38.0000
        // Supplier MOQ = 20, Multiple = 5
        // ceil(38 / 5) * 5 = 8 * 5 = 40.0000 >= MOQ (20) -> suggested_quantity = 40.0000
        $this->updateStockBalance($this->productA, '12.0000', '15.0000', '20.0000', '50.0000');

        $suggestion = $this->reorderService->generateItemSuggestion(
            $this->tenant->id,
            $this->store->id,
            'product',
            $this->productA->id,
            ['reorder_point' => '20.0000', 'target_max_stock' => '50.0000']
        );

        $this->assertNotNull($suggestion);
        $this->assertSame(ReorderSuggestionStatusEnum::Open, $suggestion->status);
        $this->assertSame('12.0000', $suggestion->current_stock);
        $this->assertSame('12.0000', $suggestion->net_inventory_position);
        $this->assertSame('38.0000', $suggestion->raw_deficit_qty);
        $this->assertSame('40.0000', $suggestion->suggested_quantity);
        $this->assertSame('BOTOL', $suggestion->purchase_uom);
        $this->assertSame($this->supplier->id, $suggestion->preferred_supplier_id);
    }

    public function test_reorder_suggestion_strictly_enforces_moq(): void
    {
        // On-Hand = 18.0000, ROP = 20.0000, MaxStock = 25.0000
        // Raw Deficit = 25.0000 - 18.0000 = 7.0000
        // Product A memiliki MOQ = 20.0000, Multiple = 5.0000
        // Kebutuhan mentah 7 -> kelipatan = 10, tetapi MOQ = 20 -> disarankan 20.0000!
        $this->updateStockBalance($this->productA, '18.0000', '15.0000', '20.0000', '25.0000');

        $suggestion = $this->reorderService->generateItemSuggestion(
            $this->tenant->id,
            $this->store->id,
            'product',
            $this->productA->id,
            ['reorder_point' => '20.0000', 'target_max_stock' => '25.0000']
        );

        $this->assertNotNull($suggestion);
        $this->assertSame('7.0000', $suggestion->raw_deficit_qty);
        $this->assertSame('20.0000', $suggestion->suggested_quantity, 'Saran kuantitas wajib menaikkan defisit ke ambang MOQ pemasok.');
    }

    public function test_reorder_suggestion_strictly_enforces_order_multiple(): void
    {
        // Product B: MOQ = 10.0000, Multiple = 10.0000
        // On-Hand = 5.0000, ROP = 10.0000, MaxStock = 32.0000
        // Raw Deficit = 32 - 5 = 27.0000
        // Kelipatan 10: ceil(27 / 10) * 10 = 30.0000
        $this->updateStockBalance($this->productB, '5.0000', '10.0000', '10.0000', '32.0000');

        $suggestion = $this->reorderService->generateItemSuggestion(
            $this->tenant->id,
            $this->store->id,
            'product',
            $this->productB->id,
            ['reorder_point' => '10.0000', 'target_max_stock' => '32.0000']
        );

        $this->assertNotNull($suggestion);
        $this->assertSame('27.0000', $suggestion->raw_deficit_qty);
        $this->assertSame('30.0000', $suggestion->suggested_quantity, 'Saran kuantitas wajib membulatkan defisit ke kelipatan order terdekat.');
    }

    public function test_reorder_suggestion_safety_invariant_creates_no_stock_movement_or_po_and_converts_to_draft_pr_only(): void
    {
        // Invarian Keamanan (AT-14):
        // ReorderSuggestion HANYA bersifat read-only ('open') dan TIDAK PERNAH memutasikan stok atau membuat PO otomatis.
        $this->updateStockBalance($this->productA, '5.0000', '0.0000', '20.0000', '50.0000');

        $initialMovementCount = StockMovement::query()->count();
        $initialPoCount = PurchaseOrder::query()->count();
        $initialPrCount = PurchaseRequest::query()->count();

        // 1. Eksekusi Mesin Reorder
        $suggestion = $this->reorderService->generateItemSuggestion(
            $this->tenant->id,
            $this->store->id,
            'product',
            $this->productA->id,
            ['reorder_point' => '20.0000', 'target_max_stock' => '50.0000']
        );

        $this->assertNotNull($suggestion);
        $this->assertSame(ReorderSuggestionStatusEnum::Open, $suggestion->status);

        // Verifikasi TIDAK ADA mutasi stok atau PO yang terbuat
        $this->assertSame($initialMovementCount, StockMovement::query()->count(), 'Mesin saran pemesanan ulang dilarang membuat mutasi stok!');
        $this->assertSame($initialPoCount, PurchaseOrder::query()->count(), 'Mesin saran pemesanan ulang dilarang membuat Purchase Order otomatis!');
        $this->assertSame($initialPrCount, PurchaseRequest::query()->count(), 'Mesin saran pemesanan ulang dilarang membuat PR otomatis tanpa konversi!');

        // 2. Konversi Eksplisit oleh Pengguna Terotorisasi
        $createdPr = $this->reorderService->convertSuggestionsToPurchaseRequest(
            $this->tenant->id,
            $this->store->id,
            [$suggestion->id],
            $this->purchasingUser
        );

        // Verifikasi PR yang dihasilkan berstatus Draf
        $this->assertTrue($createdPr->isDraft());
        $this->assertCount(1, $createdPr->lines);
        $this->assertSame('45.0000', $createdPr->lines->first()->requested_qty);

        // Verifikasi status suggestion berubah menjadi converted dan mengacu ke PR
        $suggestion->refresh();
        $this->assertSame(ReorderSuggestionStatusEnum::Converted, $suggestion->status);
        $this->assertSame($createdPr->id, $suggestion->converted_purchase_request_id);

        // Mutasi stok tetap 0 karena masih draf PR
        $this->assertSame($initialMovementCount, StockMovement::query()->count());
    }

    public function test_reorder_suggestion_can_be_dismissed_with_reason(): void
    {
        $this->updateStockBalance($this->productA, '2.0000', '0.0000', '10.0000', '30.0000');

        $suggestion = $this->reorderService->generateItemSuggestion(
            $this->tenant->id,
            $this->store->id,
            'product',
            $this->productA->id,
            ['reorder_point' => '10.0000', 'target_max_stock' => '30.0000']
        );

        $this->assertNotNull($suggestion);
        $this->assertSame(ReorderSuggestionStatusEnum::Open, $suggestion->status);

        $dismissed = $this->reorderService->dismissSuggestion(
            $suggestion,
            'Menu produk sedang dalam evaluasi pergantian resep.',
            $this->purchasingUser
        );

        $this->assertSame(ReorderSuggestionStatusEnum::Dismissed, $dismissed->status);
        $this->assertSame('Menu produk sedang dalam evaluasi pergantian resep.', $dismissed->dismissed_reason);
    }

    public function test_supplier_performance_log_recorded_on_grn_posting_and_calculates_ewma_lead_time(): void
    {
        // 1. Buat dan rilis PO
        $po = $this->poService->createDirectPurchaseOrder(
            [
                'store_id' => $this->store->id,
                'supplier_id' => $this->supplier->id,
                'order_date' => Carbon::now()->subDays(5)->toDateString(),
                'expected_delivery_date' => Carbon::now()->subDays(1)->toDateString(),
            ],
            [
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $this->productA->id,
                    'purchase_uom' => 'BOTOL',
                    'uom_conversion_factor' => '1.000000',
                    'ordered_qty' => '20.0000',
                    'unit_price' => '18000.0000',
                ],
            ],
            $this->purchasingUser
        );
        $po = $this->poService->submitPurchaseOrder($po, $this->purchasingUser);
        $po = $this->poService->releasePurchaseOrder($po, $this->purchasingUser);

        // Manipulasi released_at ke 4 hari lalu untuk pengujian lead time yang eksak
        $po->released_at = Carbon::now()->subDays(4);
        $po->save();

        // 2. Buat dan posting GRN penerimaan penuh
        $grn = $this->grnService->createGoodsReceivedNote(
            po: $po,
            headerData: [
                'received_at' => Carbon::now()->toDateTimeString(),
            ],
            linesData: [
                [
                    'purchase_order_line_id' => $po->lines->first()->id,
                    'received_qty' => '20.0000',
                    'accepted_qty' => '20.0000',
                    'rejected_qty' => '0.0000',
                ],
            ],
            actor: $this->purchasingUser
        );

        $postedGrn = $this->grnService->postGrn($grn, $this->purchasingUser);
        $this->assertTrue($postedGrn->isPosted());

        // 3. Verifikasi SupplierPerformanceLog tercatat secara akurat
        $log = SupplierPerformanceLog::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('goods_received_note_id', $postedGrn->id)
            ->first();

        $this->assertNotNull($log, 'Log performa pemasok harus otomatis dibuat saat GRN diposting.');
        $this->assertSame($this->supplier->id, $log->supplier_id);
        $this->assertSame('product', $log->purchasable_type);
        $this->assertSame($this->productA->id, $log->purchasable_id);
        $this->assertSame('20.0000', $log->ordered_qty);
        $this->assertSame('20.0000', $log->accepted_qty);
        $this->assertSame('18000.0000', $log->unit_price);
        $this->assertNotNull($log->order_to_first_receipt_days);
        $this->assertNotNull($log->order_to_full_receipt_days);

        // 4. Verifikasi kalkulasi EWMA Lead Time
        $ewmaLeadTime = $this->performanceService->calculateEwmaLeadTime(
            tenantId: $this->tenant->id,
            supplierId: $this->supplier->id,
            purchasableType: 'product',
            purchasableId: $this->productA->id
        );

        $this->assertNotNull($ewmaLeadTime);
        $this->assertGreaterThan(0.0, $ewmaLeadTime);
    }
}
