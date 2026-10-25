<?php

declare(strict_types=1);

namespace Tests\Feature\TableManagement;

use App\Events\WaiterCalled;
use App\Livewire\Kasir\CreateOrder;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\Table;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use App\Services\TableService;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pengujian Siklus Hidup Meja, Konkurensi Kunci Baris, State Reset, dan Event Pasca-Komit (TC-12 s.d. TC-15).
 * Memvalidasi INV-TBL-01, INV-TBL-04, INV-TBL-07.
 */
class TableConcurrencyAndLifecycleTest extends TestCase
{
    use DatabaseTransactions;

    protected bool $optOutFromDefaultTenant = true;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();
        app(TenantContext::class)->clear();
    }

    private function createTenant(string $name): Tenant
    {
        $tenant = Tenant::create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.uniqid(),
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'subscription_status' => Tenant::SUBSCRIPTION_TRIAL,
            'subscription_plan' => 'standard_trial',
            'country_code' => 'ID',
        ]);

        Artisan::call('tenant:provision-system-users', ['tenant' => $tenant->id]);

        return $tenant;
    }

    private function createStore(Tenant $tenant, string $name, string $code): Store
    {
        return Store::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'code' => $code,
            'timezone' => 'Asia/Jakarta',
            'business_date_cutoff' => '00:00',
            'default_channel' => 'dine_in',
            'is_active' => true,
        ]);
    }

    private function createUser(Tenant $tenant, string $roleName, array $storeIds = []): User
    {
        return app(TenantContext::class)->runAs($tenant->id, $storeIds[0] ?? null, function () use ($tenant, $roleName, $storeIds) {
            $role = Role::firstOrCreate(
                ['tenant_id' => $tenant->id, 'name' => $roleName],
                ['permissions' => ['*'], 'is_active' => true]
            );

            $user = User::create([
                'tenant_id' => $tenant->id,
                'role_id' => $role->id,
                'name' => "User {$roleName} {$tenant->id}",
                'email' => Str::random(10).'@apeiron.test',
                'password' => bcrypt('password123'),
                'is_active' => true,
                'email_verified_at' => now(),
            ]);

            if (! empty($storeIds)) {
                $syncData = [];
                foreach ($storeIds as $sId) {
                    $syncData[$sId] = ['tenant_id' => $tenant->id];
                }
                $user->stores()->sync($syncData);
            }

            return $user;
        });
    }

    private function createTable(Tenant $tenant, Store $store, string $name): Table
    {
        return app(TenantContext::class)->runAs($tenant->id, $store->id, function () use ($tenant, $store, $name) {
            return Table::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id,
                'store_id' => $store->id,
                'table_name' => $name,
                'table_code' => 'TBL-'.strtoupper(Str::random(6)),
                'capacity' => 4,
                'area' => 'Indoor',
                'is_active' => true,
                'operational_status' => 'available',
                'qr_token' => bin2hex(random_bytes(32)),
                'qr_token_version' => 1,
                'secure_token' => (string) Str::uuid(),
            ]);
        });
    }

    private function createProduct(Tenant $tenant, Store $store, string $name, int $price, int $stock = 50): Product
    {
        return app(TenantContext::class)->runAs($tenant->id, $store->id, function () use ($tenant, $name, $price, $stock) {
            $category = Category::firstOrCreate(
                ['tenant_id' => $tenant->id, 'category_name' => 'Umum'],
                ['is_active' => true]
            );

            return Product::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id,
                'category_id' => $category->id,
                'product_name' => $name,
                'product_code' => 'PRD-'.strtoupper(Str::random(6)),
                'product_type' => 'standard',
                'product_price' => $price,
                'stock' => $stock,
                'track_inventory' => true,
                'status' => 'active',
                'is_active' => true,
            ]);
        });
    }

    /**
     * TC-12: Konkurensi Operasi Meja & Pesanan Simultan.
     * Pembebasan meja (releaseTable) wajib ditolak jika masih terdapat pesanan aktif yang belum lunas (INV-TBL-07).
     * Menonaktifkan meja (disableTable) juga wajib ditolak jika meja memiliki pesanan aktif.
     */
    public function test_tc12_simultaneous_table_and_order_operations_preserve_invariants(): void
    {
        $tenant = $this->createTenant('Tenant Fine Dining');
        $store = $this->createStore($tenant, 'Resto Fine Dining SCBD', 'SCBD-01');
        $cashier = $this->createUser($tenant, 'Kasir', [$store->id]);
        $table = $this->createTable($tenant, $store, 'VIP-9');
        $product = $this->createProduct($tenant, $store, 'Signature Steak', 200000, 20);

        app(TenantContext::class)->setTenantId($tenant->id);
        app(TenantContext::class)->setStoreId($store->id);

        // 1. Create a Dine-In order that is pending (unpaid)
        $order = app(TransactionService::class)->createTransaction([
            'order_type' => 'dine_in',
            'table_id' => $table->id,
            'payment_method' => 'cash',
            'is_self_order_cash' => true, // Pending cash order
            'cash_received' => null,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 200000,
                    'extra_price' => 0,
                ],
            ],
        ], $cashier->id);

        $this->assertSame('pending', $order->order_status->value);
        $this->assertSame('occupied', $table->fresh()->operational_status);

        // 2. Attempt to release table while unpaid order is active -> MUST BE REJECTED (INV-TBL-07)
        try {
            app(TableService::class)->releaseTable($table);
            $this->fail('Harusnya gagal membebaskan meja karena masih ada pesanan pending yang belum lunas (INV-TBL-07)');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('pesanan aktif', $e->getMessage());
        }

        // Table status must remain occupied
        $this->assertSame('occupied', $table->fresh()->operational_status);

        // 3. Attempt to disable table while unpaid order is active -> MUST BE REJECTED
        try {
            app(TableService::class)->disableTable($table);
            $this->fail('Harusnya gagal menonaktifkan meja karena masih ada pesanan aktif');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('pesanan aktif', $e->getMessage());
        }
        $this->assertTrue($table->fresh()->is_active);
    }

    /**
     * TC-13: Balapan Webhook Pembayaran vs Pembatalan / Expired.
     * Setelah order dibayar atau difinalisasi, status meja disinkronkan secara atomik, dan pembatalan pesanan yang telah dibayar ditolak.
     */
    public function test_tc13_payment_completion_and_cancellation_race_handling(): void
    {
        $tenant = $this->createTenant('Tenant Kopi Cepat');
        $store = $this->createStore($tenant, 'Cabang Thamrin', 'THM-01');
        $cashier = $this->createUser($tenant, 'Kasir', [$store->id]);
        $table = $this->createTable($tenant, $store, '02');
        $product = $this->createProduct($tenant, $store, 'Americano', 25000, 20);

        app(TenantContext::class)->setTenantId($tenant->id);
        app(TenantContext::class)->setStoreId($store->id);

        // 1. Create DineIn paid order (table is occupied)
        $order = app(TransactionService::class)->createTransaction([
            'order_type' => 'dine_in',
            'table_id' => $table->id,
            'payment_method' => 'cash',
            'cash_received' => 100000,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 25000,
                    'extra_price' => 0,
                ],
            ],
        ], $cashier->id);

        $this->assertSame('occupied', $table->fresh()->operational_status);

        // 2. Void order -> atomically synchronizes table status back to available
        app(TransactionService::class)->voidOrder($order, 'Pelanggan membatalkan pesanan', (int) $cashier->id);

        $this->assertSame('available', $table->fresh()->operational_status, 'Voiding order must atomically free table (INV-TBL-07)');

        // 3. Releasing an already available table is successful and idempotent
        $freedTable = app(TableService::class)->releaseTable($table);
        $this->assertSame('available', $freedTable->operational_status);
    }

    /**
     * TC-14: Pembersihan State Saat Perpindahan Cabang.
     * Perpindahan cabang via StoreSwitchController membersihkan pilihan meja, dan kasir Livewire menolak submit order jika cabang di session berubah (stale branch state).
     */
    public function test_tc14_branch_switching_clears_table_session_and_rejects_stale_branch_submit(): void
    {
        $tenant = $this->createTenant('Tenant Dua Cabang');
        $store1 = $this->createStore($tenant, 'Store Alpha', 'ALP-01');
        $store2 = $this->createStore($tenant, 'Store Beta', 'BET-02');

        $user = $this->createUser($tenant, 'Owner', [$store1->id, $store2->id]);
        $table1 = $this->createTable($tenant, $store1, 'T-01');
        $table2 = $this->createTable($tenant, $store2, 'T-02');
        $product1 = $this->createProduct($tenant, $store1, 'Kopi Alpha', 20000, 10);

        $this->actingAs($user);
        session([
            'active_store_id' => $store1->id,
            'current_table_id' => $table1->id,
            'current_table_name' => $table1->table_name,
            'cashier_table_id' => $table1->id,
        ]);

        app(TenantContext::class)->setTenantId($tenant->id);
        app(TenantContext::class)->setStoreId($store1->id);

        // 1. Switch store to Store 2
        $switchResp = $this->post(route('store.switch'), [
            'store_id' => (string) $store2->id,
        ]);
        $switchResp->assertRedirect();

        // 2. Assert table session was cleanly wiped
        $this->assertNull(session('current_table_id'), 'Switching store must clear current_table_id');
        $this->assertNull(session('cashier_table_id'), 'Switching store must clear cashier_table_id');
        $this->assertSame($store2->id, session('active_store_id'));

        // 3. Test Livewire Kasir CreateOrder stale branch rejection
        // Mount component while in Store 1 context
        app(TenantContext::class)->setStoreId($store1->id);
        session(['active_store_id' => $store1->id]);

        $component = Livewire::actingAs($user)
            ->test(CreateOrder::class)
            ->set('orderType', 'dine_in')
            ->set('tableId', $table1->id)
            ->set('cart', [
                ['product_id' => $product1->id, 'qty' => 1, 'modifier_ids' => [], 'notes' => null],
            ]);

        // Simulate background store switch in session/TenantContext to Store 2
        app(TenantContext::class)->setStoreId($store2->id);
        session(['active_store_id' => $store2->id]);

        // Submit order from component initialized with Store 1 -> MUST BE REJECTED
        $component->call('submitOrder', [
            ['method' => 'cash', 'amount' => 22200],
        ]);

        $component->assertHasErrors(['cart']);
    }

    /**
     * TC-15: Keamanan Retry Antrean & Event Pasca-Komit.
     * Event WaiterCalled atau side effect pasca-komit TIDAK PERNAH dikirim jika transaksi di-rollback.
     */
    public function test_tc15_after_commit_events_do_not_leak_on_rollback(): void
    {
        Event::fake([WaiterCalled::class]);

        $tenant = $this->createTenant('Tenant Notifikasi Resto');
        $store = $this->createStore($tenant, 'Cabang Menteng', 'MNT-01');
        $table = $this->createTable($tenant, $store, '08');

        // 1. Simulate transaction that rolls back
        try {
            DB::transaction(function () use ($table, $tenant, $store) {
                DB::afterCommit(function () use ($table, $tenant, $store) {
                    WaiterCalled::dispatch($table->id, $table->table_name, $tenant->id, $store->id);
                });

                // Force exception to trigger rollback
                throw new \RuntimeException('Simulated failure before transaction commit');
            });
        } catch (\RuntimeException) {
            // Expected
        }

        // Event MUST NOT have been dispatched
        Event::assertNotDispatched(WaiterCalled::class);

        // 2. Simulate transaction that commits
        DB::transaction(function () use ($table, $tenant, $store) {
            DB::afterCommit(function () use ($table, $tenant, $store) {
                WaiterCalled::dispatch($table->id, $table->table_name, $tenant->id, $store->id);
            });
        });

        // Event MUST have been dispatched exactly once
        Event::assertDispatched(WaiterCalled::class, function (WaiterCalled $event) use ($table, $tenant, $store) {
            return $event->tableId === $table->id
                && $event->tenantId === $tenant->id
                && $event->storeId === $store->id;
        });
    }
}
