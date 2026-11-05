<?php

declare(strict_types=1);

namespace Tests\Feature\FNB;

use App\Enums\OrderStatus;
use App\Enums\PreparationStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\Table;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\FNB\Services\SplitBillService;
use App\Modules\FNB\Services\TableManagementService;
use App\Modules\Sales\Services\KdsService;
use App\Services\Context\TenantContext;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class Wave4FnbTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;

    private Store $store;

    private User $waiter;

    private User $cook;

    private Table $table1;

    private Table $table2;

    private Table $table3;

    private Category $kitchenCategory;

    private Category $barCategory;

    private TableManagementService $tableManagementService;

    private SplitBillService $splitBillService;

    private KdsService $kdsService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = Tenant::create([
            'name' => 'Wave 4 FNB Tenant',
            'slug' => 'wave4-fnb-'.uniqid(),
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'FNB Bistro Store',
            'code' => 'FNB-01',
            'is_active' => true,
        ]);

        app(TenantContext::class)->setStoreId($this->store->id);

        $roleWaiter = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Waiter',
            'permissions' => ['fnb.table', 'fnb.order'],
        ]);

        $roleCook = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cook',
            'permissions' => ['kds.*'],
        ]);

        $this->waiter = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $roleWaiter->id,
            'password' => Hash::make('password123'),
        ]);

        $this->cook = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $roleCook->id,
            'password' => Hash::make('password123'),
        ]);

        DB::table('user_stores')->insert([
            ['tenant_id' => $this->tenant->id, 'user_id' => $this->waiter->id, 'store_id' => $this->store->id],
            ['tenant_id' => $this->tenant->id, 'user_id' => $this->cook->id, 'store_id' => $this->store->id],
        ]);

        // Buat Meja
        $this->table1 = Table::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'table_name' => 'T01',
            'table_code' => 'TBL-01',
            'capacity' => 4,
            'area' => 'Dining Hall',
            'is_active' => true,
            'operational_status' => 'available',
            'secure_token' => (string) Str::uuid(),
        ]);

        $this->table2 = Table::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'table_name' => 'T02',
            'table_code' => 'TBL-02',
            'capacity' => 2,
            'area' => 'Dining Hall',
            'is_active' => true,
            'operational_status' => 'available',
            'secure_token' => (string) Str::uuid(),
        ]);

        $this->table3 = Table::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'table_name' => 'T03',
            'table_code' => 'TBL-03',
            'capacity' => 6,
            'area' => 'Outdoor Patio',
            'is_active' => true,
            'operational_status' => 'available',
            'secure_token' => (string) Str::uuid(),
        ]);

        $this->kitchenCategory = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Kitchen Food',
        ]);

        $this->barCategory = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Bar Drinks',
        ]);

        $this->tableManagementService = app(TableManagementService::class);
        $this->splitBillService = app(SplitBillService::class);
        $this->kdsService = app(KdsService::class);
    }

    /**
     * T-FNB-01: Pemesanan meja F&B memicu kartu pesanan baru pada stasiun KDS yang sesuai.
     */
    public function test_t_fnb_01_fnb_table_order_dispatches_kds_card(): void
    {
        // 1. Check-in meja
        $this->tableManagementService->checkInTable(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            tableId: $this->table1->id,
            guestCount: 3,
            userId: $this->waiter->id
        );

        $foodProduct = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->kitchenCategory->id,
            'product_name' => 'Steak Wagyu Ribeye',
            'product_price' => 250000,
            'status' => 'active',
            'is_active' => true,
        ]);

        $drinkProduct = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->barCategory->id,
            'product_name' => 'Iced Lychee Tea',
            'product_price' => 35000,
            'status' => 'active',
            'is_active' => true,
        ]);

        // Buat pesanan meja
        $order = Order::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->waiter->id,
            'table_id' => $this->table1->id,
            'table_number' => $this->table1->table_name,
            'order_type' => 'dine_in',
            'origin_channel' => 'qr_table',
            'order_code' => 'ORD-FNB-'.uniqid(),
            'idempotency_key' => (string) Str::uuid(),
            'order_date' => now()->toDateString(),
            'subtotal_amount' => 285000,
            'order_amount' => 285000,
        ]);
        $order->forceFill(['order_status' => OrderStatus::Paid])->save();

        $foodItem = OrderItem::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $order->id,
            'product_id' => $foodProduct->id,
            'product_name_snapshot' => $foodProduct->product_name,
            'qty' => 1,
            'order_price' => 250000,
            'order_subtotal' => 250000,
            'preparation_status' => 'pending',
        ]);

        $drinkItem = OrderItem::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $order->id,
            'product_id' => $drinkProduct->id,
            'product_name_snapshot' => $drinkProduct->product_name,
            'qty' => 1,
            'order_price' => 35000,
            'order_subtotal' => 35000,
            'preparation_status' => 'pending',
        ]);

        // Verifikasi item berstatus PENDING di dapur
        $this->assertEquals(PreparationStatus::Pending, $foodItem->preparation_status);
        $this->assertEquals(PreparationStatus::Pending, $drinkItem->preparation_status);

        // Koki mengklaim item makanan (Pending -> Brewing/Cooking)
        $claimedFood = $this->kdsService->claim($foodItem->id, $this->cook->id);
        $this->assertEquals(PreparationStatus::Brewing, $claimedFood->preparation_status);
        $this->assertEquals($this->cook->id, $claimedFood->processed_by);

        // Koki menyelesaikan masakan (Brewing -> Ready)
        $readyFood = $this->kdsService->markReady($foodItem->id, $this->cook->id);
        $this->assertEquals(PreparationStatus::Ready, $readyFood->preparation_status);

        // Waiter menyajikan masakan ke meja tamu (Ready -> Served)
        $servedFood = $this->kdsService->markServed($foodItem->id, $this->waiter->id);
        $this->assertEquals(PreparationStatus::Ready, $servedFood->preparation_status);
    }

    /**
     * T-FNB-02: Pemisahan tagihan (split bill) menghasilkan sub-orders yang jika dijumlahkan
     * persis sama dengan total pesanan asal (baik split by item maupun split by amount).
     */
    public function test_t_fnb_02_split_bill_by_item_and_amount_sum_equals_original_order(): void
    {
        $productA = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->kitchenCategory->id,
            'product_name' => 'Pasta Carbonara',
            'product_price' => 75000,
            'is_active' => true,
        ]);

        $productB = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->kitchenCategory->id,
            'product_name' => 'Pizza Margherita',
            'product_price' => 125000,
            'is_active' => true,
        ]);

        $parentTotal = 275000; // 2x Pasta (150.000) + 1x Pizza (125.000)

        $parentOrder = Order::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->waiter->id,
            'table_id' => $this->table1->id,
            'table_number' => $this->table1->table_name,
            'order_type' => 'dine_in',
            'order_code' => 'ORD-PARENT-'.uniqid(),
            'idempotency_key' => (string) Str::uuid(),
            'order_date' => now()->toDateString(),
            'subtotal_amount' => $parentTotal,
            'order_amount' => $parentTotal,
            'order_status' => 'pending',
        ]);

        $itemA = OrderItem::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $parentOrder->id,
            'product_id' => $productA->id,
            'product_name_snapshot' => $productA->product_name,
            'qty' => 2,
            'order_price' => 75000,
            'order_subtotal' => 150000,
            'preparation_status' => 'pending',
        ]);

        $itemB = OrderItem::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $parentOrder->id,
            'product_id' => $productB->id,
            'product_name_snapshot' => $productB->product_name,
            'qty' => 1,
            'order_price' => 125000,
            'order_subtotal' => 125000,
            'preparation_status' => 'pending',
        ]);

        // UJI 1: Split By Item (Tamu 1 bayar 1 Pasta = 75k, Tamu 2 bayar 1 Pasta + 1 Pizza = 200k)
        $splitsByItem = [
            [
                'sub_order_label' => 'Tamu 1',
                'items' => [
                    ['order_item_id' => $itemA->id, 'quantity' => 1],
                ],
            ],
            [
                'sub_order_label' => 'Tamu 2',
                'items' => [
                    ['order_item_id' => $itemA->id, 'quantity' => 1],
                    ['order_item_id' => $itemB->id, 'quantity' => 1],
                ],
            ],
        ];

        $subOrders = $this->splitBillService->splitByItem(
            order: $parentOrder,
            splits: $splitsByItem,
            userId: $this->waiter->id
        );

        $this->assertCount(2, $subOrders);

        // PARITAS TOTAL (T-FNB-02): Sum sub-orders wajib sama persis dengan total pesanan asal
        $sumSubOrders = array_sum(array_map(fn ($o) => (int) $o->order_amount, $subOrders));
        $this->assertEquals($parentTotal, $sumSubOrders);

        // Status pesanan induk berubah menjadi cancelled (split)
        $parentOrder->refresh();
        $this->assertEquals(OrderStatus::Cancelled, $parentOrder->order_status);

        // UJI 2: Split By Amount pada pesanan kedua (Equal Split 100k + 100k)
        $order2Total = 200000;
        $order2 = Order::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->waiter->id,
            'table_id' => $this->table2->id,
            'table_number' => $this->table2->table_name,
            'order_type' => 'dine_in',
            'order_code' => 'ORD-AMOUNT-'.uniqid(),
            'idempotency_key' => (string) Str::uuid(),
            'order_date' => now()->toDateString(),
            'subtotal_amount' => $order2Total,
            'order_amount' => $order2Total,
            'order_status' => 'pending',
        ]);

        $subOrdersAmount = $this->splitBillService->splitByAmount(
            order: $order2,
            amounts: [100000, 100000],
            userId: $this->waiter->id
        );

        $this->assertCount(2, $subOrdersAmount);
        $sumAmountSplit = array_sum(array_map(fn ($o) => (int) $o->order_amount, $subOrdersAmount));
        $this->assertEquals($order2Total, $sumAmountSplit);
    }

    /**
     * Siklus hidup meja: Check-in, Table Move, Table Merge, Proforma Bill, dan Release Table.
     */
    public function test_table_lifecycle_operations(): void
    {
        // 1. Check-in Meja 1
        $table1 = $this->tableManagementService->checkInTable(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            tableId: $this->table1->id,
            guestCount: 2,
            userId: $this->waiter->id
        );
        $this->assertEquals('occupied', $table1->operational_status);

        // Buat pesanan aktif di Meja 1
        $order1 = Order::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->waiter->id,
            'table_id' => $table1->id,
            'table_number' => $table1->table_name,
            'order_type' => 'dine_in',
            'order_code' => 'ORD-TBL1-'.uniqid(),
            'idempotency_key' => (string) Str::uuid(),
            'order_date' => now()->toDateString(),
            'subtotal_amount' => 150000,
            'order_amount' => 150000,
            'order_status' => 'pending',
        ]);

        // 2. Proforma Bill (Wajib tanda tegas BUKAN BUKTI BAYAR)
        $proforma = $this->tableManagementService->generateProformaBill(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            tableId: $table1->id
        );
        $this->assertEquals('TAGIHAN SEMENTARA - BUKAN BUKTI PEMBAYARAN', $proforma['disclaimer']);
        $this->assertTrue($proforma['is_proforma']);
        $this->assertEquals(150000, $proforma['order']['total_amount']);

        // 3. Move Table dari Meja 1 ke Meja 2
        $moveResult = $this->tableManagementService->moveTable(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            sourceTableId: $this->table1->id,
            targetTableId: $this->table2->id,
            userId: $this->waiter->id
        );

        $this->assertEquals('available', $moveResult['source_table']->operational_status);
        $this->assertEquals('occupied', $moveResult['target_table']->operational_status);

        $order1->refresh();
        $this->assertEquals($this->table2->id, $order1->table_id);

        // 4. Upaya release table saat order belum lunas wajib ditolak
        try {
            $this->tableManagementService->releaseTableAfterPayment(
                tenantId: $this->tenant->id,
                storeId: $this->store->id,
                tableId: $this->table2->id
            );
            $this->fail('Pelepasan meja dengan pesanan pending seharusnya ditolak.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('belum lunas', $e->getMessage());
        }

        // 5. Lunasi order dan release table berhasil
        $order1->forceFill(['order_status' => OrderStatus::Paid])->save();

        $released = $this->tableManagementService->releaseTableAfterPayment(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            tableId: $this->table2->id
        );
        $this->assertTrue($released);

        $this->table2->refresh();
        $this->assertEquals('available', $this->table2->operational_status);
    }
}
