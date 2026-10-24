<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Core\Context\TenantContext;
use App\Enums\CostAllocationMethodEnum;
use App\Enums\PurchaseCostCategoryEnum;
use App\Enums\PurchaseCostStatusEnum;
use App\Enums\SupplierStatusEnum;
use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Purchasing\Models\AccountingPayableOutbox;
use App\Modules\Purchasing\Models\GoodsReceivedNote;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\GoodsReceivedNoteService;
use App\Modules\Purchasing\Services\LandedCostService;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LandedCostAllocationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private User $purchasingUser;

    private Supplier $supplier;

    private Product $product1;

    private Product $product2;

    private Product $product3;

    private PurchaseOrderService $poService;

    private GoodsReceivedNoteService $grnService;

    private LandedCostService $landedCostService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = Tenant::create([
            'name' => 'Resto Landed Group',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'status' => 'active',
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cabang Sentra Landed',
            'status' => 'active',
        ]);

        app(TenantContext::class)->setStoreId($this->store->id);

        $role = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Owner',
            'permissions' => ['*'],
            'is_admin' => true,
        ]);

        $this->purchasingUser = User::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
            'name' => 'Purchasing Lead',
            'email' => 'purchasing.lead@landed.test',
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);

        $this->purchasingUser->stores()->attach($this->store->id, ['tenant_id' => $this->tenant->id]);

        $this->supplier = Supplier::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'SUP-LAND-01',
            'name' => 'PT Impor Nusantara Jaya',
            'status' => SupplierStatusEnum::Active,
            'payment_terms_days' => 30,
            'currency_code' => 'IDR',
        ]);

        $cat = Category::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Bahan Baku Impor',
            'slug' => 'bahan-baku-impor',
        ]);

        $this->product1 = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $cat->id,
            'name' => 'Item Alpha',
            'sku' => 'PRD-ALP-01',
            'price' => '25000.00',
        ]);

        $this->product2 = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $cat->id,
            'name' => 'Item Beta',
            'sku' => 'PRD-BET-02',
            'price' => '45000.00',
        ]);

        $this->product3 = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $cat->id,
            'name' => 'Item Gamma',
            'sku' => 'PRD-GAM-03',
            'price' => '70000.00',
        ]);

        $this->poService = app(PurchaseOrderService::class);
        $this->grnService = app(GoodsReceivedNoteService::class);
        $this->landedCostService = app(LandedCostService::class);
    }

    /**
     * Helper membuat PO dan memposting GRN dengan 3 baris item.
     *
     * @return array{po: PurchaseOrder, grn: GoodsReceivedNote}
     */
    private function createAndPostThreeLineGrn(): array
    {
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
                    'purchasable_id' => $this->product1->id,
                    'purchase_uom' => 'PCS',
                    'uom_conversion_factor' => '1.000000',
                    'ordered_qty' => '10.0000',
                    'unit_price' => '10000.0000', // Nilai = 100.000
                ],
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $this->product2->id,
                    'purchase_uom' => 'PCS',
                    'uom_conversion_factor' => '1.000000',
                    'ordered_qty' => '10.0000',
                    'unit_price' => '20000.0000', // Nilai = 200.000
                ],
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $this->product3->id,
                    'purchase_uom' => 'PCS',
                    'uom_conversion_factor' => '1.000000',
                    'ordered_qty' => '10.0000',
                    'unit_price' => '30000.0000', // Nilai = 300.000
                ],
            ],
            $this->purchasingUser
        );

        $po = $this->poService->submitPurchaseOrder($po, $this->purchasingUser);
        $po = $this->poService->releasePurchaseOrder($po, $this->purchasingUser);

        $linesData = [];
        foreach ($po->lines as $pol) {
            $linesData[] = [
                'purchase_order_line_id' => $pol->id,
                'received_qty' => (string) $pol->ordered_qty,
                'accepted_qty' => (string) $pol->ordered_qty,
                'rejected_qty' => '0.0000',
            ];
        }

        $grn = $this->grnService->createGoodsReceivedNote(
            $po,
            ['received_at' => now()->toDateTimeString(), 'supplier_delivery_note_no' => 'SJ-LND-333'],
            $linesData,
            $this->purchasingUser
        );

        $postedGrn = $this->grnService->postGrn($grn, $this->purchasingUser);

        return ['po' => $po->fresh(['lines']), 'grn' => $postedGrn->fresh(['lines'])];
    }

    /**
     * AT-13: Alokasi landed cost proporsional BY_VALUE dengan resolusi selisih pembulatan sen residual eksak.
     * Total biaya tambahan: Rp 100.000,00 dialokasikan ke 3 baris (rasio 1:2:3 terhadap nilai total Rp 600.000,00).
     */
    public function test_proportional_landed_cost_allocation_by_value_with_penny_rounding_residual(): void
    {
        $setup = $this->createAndPostThreeLineGrn();
        $grn = $setup['grn'];
        $grnLines = $grn->lines->sortBy('id')->values();

        $line1 = $grnLines[0];
        $line2 = $grnLines[1];
        $line3 = $grnLines[2];

        // 1. Catat Biaya Tambahan (Ongkir / Freight): Rp 100.000,00
        $cost = $this->landedCostService->recordAdditionalCost(
            [
                'store_id' => $this->store->id,
                'goods_received_note_id' => $grn->id,
                'category' => PurchaseCostCategoryEnum::Freight,
                'description' => 'Ongkos Kirim Kontainer Pelabuhan Tanjung Priok',
                'amount' => '100000.00',
                'allocation_method' => CostAllocationMethodEnum::ByValue,
                'vendor_name' => 'PT Ekspedisi Samudera',
            ],
            $this->purchasingUser
        );

        $this->assertEquals(PurchaseCostStatusEnum::Draft, $cost->status);
        $this->assertStringStartsWith('LC-', $cost->cost_code);

        // 2. Eksekusi Alokasi Landed Cost (BY_VALUE)
        $allocatedCost = $this->landedCostService->allocateLandedCost(
            $cost->id,
            [$line1->id, $line2->id, $line3->id],
            CostAllocationMethodEnum::ByValue,
            $this->purchasingUser
        );

        $this->assertEquals(PurchaseCostStatusEnum::Allocated, $allocatedCost->status);
        $this->assertNotNull($allocatedCost->allocated_at);

        $allocations = $allocatedCost->allocations()->orderBy('id', 'asc')->get();
        $this->assertCount(3, $allocations);

        // Nilai baris:
        // Line 1: 10 * 10.000 = 100.000 -> Rasio 100.000 / 600.000 = 0.16666667 -> Alokasi: 16.666,67
        // Line 2: 10 * 20.000 = 200.000 -> Rasio 200.000 / 600.000 = 0.33333333 -> Alokasi: 33.333,33
        // Line 3: 10 * 30.000 = 300.000 -> Baris Residual N: 100.000 - (16.666,67 + 33.333,33) = 50.000,00
        $alloc1 = $allocations[0];
        $alloc2 = $allocations[1];
        $alloc3 = $allocations[2];

        $this->assertEquals('16666.67', $alloc1->allocated_amount);
        $this->assertFalse($alloc1->is_residual_line);

        $this->assertEquals('33333.33', $alloc2->allocated_amount);
        $this->assertFalse($alloc2->is_residual_line);

        $this->assertEquals('50000.00', $alloc3->allocated_amount);
        $this->assertTrue($alloc3->is_residual_line, 'Baris terakhir harus menyerap sen pembulatan residual.');

        // AT-13 Invarian: Jumlah seluruh alokasi persis sama dengan total biaya tanpa selisih 1 sen pun!
        $totalAllocated = bcadd(bcadd($alloc1->allocated_amount, $alloc2->allocated_amount, 2), $alloc3->allocated_amount, 2);
        $this->assertEquals('100000.00', $totalAllocated);

        // 3. Verifikasi pembaruan pada goods_received_note_lines
        $line1->refresh();
        $line2->refresh();
        $line3->refresh();

        // Biaya per unit bertambah:
        // Line 1: 16666.67 / 10 = 1666.6670 -> effective_unit_cost = 10000 + 1666.6670 = 11666.6670
        $this->assertEquals('1666.6670', (string) $line1->allocated_landed_cost_per_unit);
        $this->assertEquals('11666.6670', (string) $line1->effective_unit_cost);

        // Line 2: 33333.33 / 10 = 3333.3330 -> effective_unit_cost = 20000 + 3333.3330 = 23333.3330
        $this->assertEquals('3333.3330', (string) $line2->allocated_landed_cost_per_unit);
        $this->assertEquals('23333.3330', (string) $line2->effective_unit_cost);

        // Line 3: 50000.00 / 10 = 5000.0000 -> effective_unit_cost = 30000 + 5000.0000 = 35000.0000
        $this->assertEquals('5000.0000', (string) $line3->allocated_landed_cost_per_unit);
        $this->assertEquals('35000.0000', (string) $line3->effective_unit_cost);
    }

    /**
     * AT-13: Alokasi landed cost proporsional BY_QUANTITY.
     */
    public function test_proportional_landed_cost_allocation_by_quantity(): void
    {
        // Buat PO 2 baris: 3 unit dan 7 unit (total 10 unit)
        $po = $this->poService->createDirectPurchaseOrder(
            [
                'store_id' => $this->store->id,
                'supplier_id' => $this->supplier->id,
                'order_date' => now()->toDateString(),
            ],
            [
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $this->product1->id,
                    'purchase_uom' => 'PCS',
                    'uom_conversion_factor' => '1.000000',
                    'ordered_qty' => '3.0000',
                    'unit_price' => '50000.0000',
                ],
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $this->product2->id,
                    'purchase_uom' => 'PCS',
                    'uom_conversion_factor' => '1.000000',
                    'ordered_qty' => '7.0000',
                    'unit_price' => '50000.0000',
                ],
            ],
            $this->purchasingUser
        );

        $po = $this->poService->submitPurchaseOrder($po, $this->purchasingUser);
        $po = $this->poService->releasePurchaseOrder($po, $this->purchasingUser);

        $linesData = [];
        foreach ($po->lines as $pol) {
            $linesData[] = [
                'purchase_order_line_id' => $pol->id,
                'received_qty' => (string) $pol->ordered_qty,
                'accepted_qty' => (string) $pol->ordered_qty,
                'rejected_qty' => '0.0000',
            ];
        }

        $grn = $this->grnService->createGoodsReceivedNote(
            $po,
            ['received_at' => now()->toDateTimeString(), 'supplier_delivery_note_no' => 'SJ-QTY-01'],
            $linesData,
            $this->purchasingUser
        );
        $postedGrn = $this->grnService->postGrn($grn, $this->purchasingUser);
        $lines = $postedGrn->lines->sortBy('id')->values();

        // Biaya Tambahan Penanganan (Handling): Rp 50.000,00 dialokasikan proporsional kuantitas
        $cost = $this->landedCostService->recordAdditionalCost(
            [
                'store_id' => $this->store->id,
                'goods_received_note_id' => $postedGrn->id,
                'category' => PurchaseCostCategoryEnum::Handling,
                'description' => 'Biaya Bongkar Muat Gudang',
                'amount' => '50000.00',
                'allocation_method' => CostAllocationMethodEnum::ByQuantity,
            ],
            $this->purchasingUser
        );

        $allocatedCost = $this->landedCostService->allocateLandedCost(
            $cost->id,
            [$lines[0]->id, $lines[1]->id],
            CostAllocationMethodEnum::ByQuantity,
            $this->purchasingUser
        );

        $allocs = $allocatedCost->allocations()->orderBy('id', 'asc')->get();
        // Line 1 (3 unit): 3/10 * 50.000 = 15.000
        // Line 2 (7 unit): 7/10 * 50.000 = 35.000
        $this->assertEquals('15000.00', $allocs[0]->allocated_amount);
        $this->assertEquals('35000.00', $allocs[1]->allocated_amount);
        $this->assertEquals('50000.00', bcadd($allocs[0]->allocated_amount, $allocs[1]->allocated_amount, 2));
    }

    /**
     * AT-13: Validasi alokasi MANUAL_EXACT:
     * Ditolak jika jumlah alokasi tidak sama persis dengan total biaya tambahan.
     */
    public function test_manual_exact_allocation_validation(): void
    {
        $setup = $this->createAndPostThreeLineGrn();
        $grn = $setup['grn'];
        $grnLines = $grn->lines->sortBy('id')->values();

        $cost = $this->landedCostService->recordAdditionalCost(
            [
                'store_id' => $this->store->id,
                'category' => PurchaseCostCategoryEnum::Insurance,
                'description' => 'Premi Asuransi Pengiriman',
                'amount' => '75000.00',
                'allocation_method' => CostAllocationMethodEnum::ManualExact,
            ],
            $this->purchasingUser
        );

        // 1. Kirim alokasi manual yang jumlahnya 70.000 (selisih 5.000) -> Wajib Ditolak
        try {
            $this->landedCostService->allocateLandedCost(
                $cost->id,
                [$grnLines[0]->id, $grnLines[1]->id],
                CostAllocationMethodEnum::ManualExact,
                $this->purchasingUser,
                [
                    $grnLines[0]->id => '30000.00',
                    $grnLines[1]->id => '40000.00', // Total = 70.000 != 75.000
                ]
            );
            $this->fail('Alokasi manual harus ditolak jika jumlahnya tidak persis sama dengan total biaya.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('manual_amounts', $e->errors());
        }

        // 2. Kirim alokasi manual yang jumlahnya pas 75.000 (35.000 + 40.000) -> Harus Berhasil
        $allocated = $this->landedCostService->allocateLandedCost(
            $cost->id,
            [$grnLines[0]->id, $grnLines[1]->id],
            CostAllocationMethodEnum::ManualExact,
            $this->purchasingUser,
            [
                $grnLines[0]->id => '35000.00',
                $grnLines[1]->id => '40000.00',
            ]
        );

        $this->assertEquals(PurchaseCostStatusEnum::Allocated, $allocated->status);
        $allocs = $allocated->allocations()->orderBy('id', 'asc')->get();
        $this->assertEquals('35000.00', $allocs[0]->allocated_amount);
        $this->assertEquals('40000.00', $allocs[1]->allocated_amount);
    }

    /**
     * AT-13 & AT-17: Alokasi Landed Cost menerbitkan rekaman outbox akuntansi M7 (LANDED_COST_ALLOCATED).
     */
    public function test_landed_cost_allocation_publishes_m7_outbox_contract(): void
    {
        $setup = $this->createAndPostThreeLineGrn();
        $grn = $setup['grn'];
        $grnLines = $grn->lines->sortBy('id')->values();

        $cost = $this->landedCostService->recordAdditionalCost(
            [
                'store_id' => $this->store->id,
                'category' => PurchaseCostCategoryEnum::CustomsDuty,
                'description' => 'Bea Cukai Jalur Merah',
                'amount' => '60000.00',
                'allocation_method' => CostAllocationMethodEnum::ByQuantity,
            ],
            $this->purchasingUser
        );

        $allocatedCost = $this->landedCostService->allocateLandedCost(
            $cost->id,
            [$grnLines[0]->id, $grnLines[1]->id, $grnLines[2]->id],
            CostAllocationMethodEnum::ByQuantity,
            $this->purchasingUser
        );

        $outbox = AccountingPayableOutbox::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('idempotency_key', "lc:alloc:{$this->tenant->id}:{$allocatedCost->id}")
            ->first();

        $this->assertNotNull($outbox, 'Outbox M7 wajib diterbitkan saat Landed Cost dialokasikan.');
        $this->assertEquals('LANDED_COST_ALLOCATED', $outbox->event_type);
        $this->assertEquals('purchase_additional_cost', $outbox->source_document_type);
        $this->assertEquals($allocatedCost->cost_code, $outbox->source_document_number);

        $payload = $outbox->payload;
        $this->assertEquals('1.0', $payload['schema_version']);
        $this->assertEquals('60000.00', $payload['total_amount']);
        $this->assertEquals(PurchaseCostCategoryEnum::CustomsDuty->value, $payload['category']);
        $this->assertCount(3, $payload['allocations']);
    }
}
