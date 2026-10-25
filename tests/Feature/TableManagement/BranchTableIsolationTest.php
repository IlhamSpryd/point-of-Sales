<?php

declare(strict_types=1);

namespace Tests\Feature\TableManagement;

use App\Models\Role;
use App\Models\Store;
use App\Models\Table;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use App\Services\TransactionService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Pengujian Invarian Isolasi Meja Lintas Tenant dan Cabang (TC-01 s.d. TC-05).
 * Memvalidasi INV-TBL-01, INV-TBL-02, dan INV-TBL-05.
 */
class BranchTableIsolationTest extends TestCase
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

    /**
     * TC-01: Isolasi Lintas Tenant.
     * Tenant A tidak boleh dapat membaca, mengubah, menghapus, atau menautkan meja milik Tenant B.
     */
    public function test_tc01_tenant_a_cannot_access_or_order_at_tenant_b_table(): void
    {
        $tenantA = $this->createTenant('Tenant Alpha');
        $storeA = $this->createStore($tenantA, 'Store Alpha 1', 'ALP-01');
        $userA = $this->createUser($tenantA, 'Owner', [$storeA->id]);
        $tableA = $this->createTable($tenantA, $storeA, 'Meja A1');

        $tenantB = $this->createTenant('Tenant Beta');
        $storeB = $this->createStore($tenantB, 'Store Beta 1', 'BET-01');
        $tableB = $this->createTable($tenantB, $storeB, 'Meja B1');

        // Login as Owner of Tenant A
        $this->actingAs($userA);
        session(['active_store_id' => $storeA->id]);
        app(TenantContext::class)->setTenantId($tenantA->id);
        app(TenantContext::class)->setStoreId($storeA->id);

        // 1. Try to access Table B via TableController (route key qr_token)
        $response = $this->get(route('tables.edit', $tableB->qr_token));
        $this->assertTrue(in_array($response->getStatusCode(), [403, 404], true));

        // 2. Try to update Table B
        $updateResp = $this->put(route('tables.update', $tableB->qr_token), [
            'table_name' => 'Hacked Name',
            'capacity' => 10,
            'area' => 'Outdoor',
            'operational_status' => 'available',
            'is_active' => 1,
        ]);
        $this->assertTrue(in_array($updateResp->getStatusCode(), [403, 404], true));
        $this->assertDatabaseMissing('tables', ['id' => $tableB->id, 'table_name' => 'Hacked Name']);

        // 3. Try to delete Table B
        $delResp = $this->delete(route('tables.destroy', $tableB->qr_token));
        $this->assertTrue(in_array($delResp->getStatusCode(), [403, 404], true));
        $this->assertNull($tableB->fresh()->deleted_at);

        // 4. Try to create Dine-In order in Tenant A with table_id = Table B
        $this->expectException(\Throwable::class);
        app(TransactionService::class)->createTransaction([
            'order_type' => 'dine_in',
            'table_id' => $tableB->id,
            'items' => [],
        ], $userA->id);
    }

    /**
     * TC-02: Isolasi Lintas Cabang dalam Satu Tenant.
     * Staf Cabang A tidak boleh memanipulasi meja Cabang B atau melihatnya dalam daftar meja.
     */
    public function test_tc02_branch_a_cannot_manipulate_or_view_branch_b_table(): void
    {
        $tenant = $this->createTenant('Tenant Kopi Satu');
        $storeA = $this->createStore($tenant, 'Cabang Jaksel', 'JS-01');
        $storeB = $this->createStore($tenant, 'Cabang Bandung', 'BDG-01');

        $managerA = $this->createUser($tenant, 'Manager', [$storeA->id]);
        $tableA = $this->createTable($tenant, $storeA, 'Meja 1');
        $tableB = $this->createTable($tenant, $storeB, 'Meja 2');

        $this->actingAs($managerA);
        session(['active_store_id' => $storeA->id]);
        app(TenantContext::class)->setTenantId($tenant->id);
        app(TenantContext::class)->setStoreId($storeA->id);

        // 1. Index should only show Table A, not Table B
        $indexResp = $this->get(route('tables.index'));
        $indexResp->assertOk();
        $tablesInView = $indexResp->viewData('tables');
        $tableIds = collect($tablesInView->items())->pluck('id')->all();
        $this->assertContains($tableA->id, $tableIds);
        $this->assertNotContains($tableB->id, $tableIds);

        // 2. Try to edit Table B
        $editResp = $this->get(route('tables.edit', $tableB->qr_token));
        $this->assertTrue(in_array($editResp->getStatusCode(), [403, 404], true));

        // 3. Try to rotate QR of Table B
        $rotateResp = $this->post(route('tables.rotate-qr', $tableB->qr_token));
        $this->assertTrue(in_array($rotateResp->getStatusCode(), [403, 404], true));
    }

    /**
     * TC-03: Penolakan Identitas Meja Palsu.
     * table_id fiktif atau qr_token palsu ditolak tanpa membuat order.
     */
    public function test_tc03_forged_table_identifier_is_rejected(): void
    {
        $tenant = $this->createTenant('Tenant Resto Maju');
        $store = $this->createStore($tenant, 'Cabang Pusat', 'PST-01');
        $cashier = $this->createUser($tenant, 'Kasir', [$store->id]);

        $this->actingAs($cashier);
        session(['active_store_id' => $store->id]);
        app(TenantContext::class)->setTenantId($tenant->id);
        app(TenantContext::class)->setStoreId($store->id);

        // 1. Transaction creation with non-existent table_id 999999
        $ordersCountBefore = DB::table('orders')->count();
        try {
            app(TransactionService::class)->createTransaction([
                'order_type' => 'dine_in',
                'table_id' => 999999,
                'items' => [],
            ], $cashier->id);
            $this->fail('Harusnya melempar exception untuk table_id palsu');
        } catch (\Throwable $e) {
            $this->assertTrue(
                $e instanceof ValidationException || $e instanceof \DomainException || $e instanceof ModelNotFoundException
            );
        }
        $this->assertSame($ordersCountBefore, DB::table('orders')->count());

        // 2. Anonymous Self-Order with forged token
        $forgedToken = str_repeat('f', 64);
        $response = $this->get(route('customer.menu.index', ['token' => $forgedToken]));
        $response->assertNotFound();
    }

    /**
     * TC-04: Pencegahan Penautan Meja Lintas Cabang pada Order.
     * Order di Cabang A1 tidak boleh ditautkan ke meja milik Cabang A2 (INV-TBL-01).
     */
    public function test_tc04_cross_branch_table_linking_is_rejected_by_service_and_database_fk(): void
    {
        $tenant = $this->createTenant('Tenant Enterprise POS');
        $storeA1 = $this->createStore($tenant, 'Store A1', 'A1-01');
        $storeA2 = $this->createStore($tenant, 'Store A2', 'A2-02');

        $owner = $this->createUser($tenant, 'Owner', [$storeA1->id, $storeA2->id]);
        $tableA2 = $this->createTable($tenant, $storeA2, 'T-A2');

        app(TenantContext::class)->setTenantId($tenant->id);
        app(TenantContext::class)->setStoreId($storeA1->id);

        // 1. TransactionService validation rejection
        try {
            app(TransactionService::class)->createTransaction([
                'order_type' => 'dine_in',
                'table_id' => $tableA2->id,
                'items' => [],
            ], $owner->id);
            $this->fail('Harusnya ditolak oleh TransactionService karena meja milik cabang lain');
        } catch (\Throwable $e) {
            $this->assertTrue(
                $e instanceof ValidationException || $e instanceof \DomainException,
                'Exception should be ValidationException or DomainException: '.$e->getMessage()
            );
        }

        // 2. Database composite FK rejection if raw insert attempted with mismatched store_id and table_id
        $this->expectException(QueryException::class);
        DB::table('orders')->insert([
            'tenant_id' => $tenant->id,
            'store_id' => $storeA1->id, // Store A1
            'table_id' => $tableA2->id, // But Table belongs to Store A2!
            'table_number' => $tableA2->table_name,
            'order_code' => 'TEST-FK-'.Str::random(6),
            'order_type' => 'dine_in',
            'order_status' => 'pending',
            'payment_status' => 'unpaid',
            'order_amount' => 10000,
            'user_id' => $owner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * TC-05: Independensi Penomoran Meja Antar Cabang.
     * Dua cabang independen dapat menggunakan nomor meja '01' yang sama.
     * Namun duplikasi nama meja dalam cabang yang sama wajib ditolak.
     */
    public function test_tc05_independent_table_numbering_per_branch(): void
    {
        $tenant = $this->createTenant('Tenant Multi-Resto');
        $store1 = $this->createStore($tenant, 'Cabang Senopati', 'SNP-01');
        $store2 = $this->createStore($tenant, 'Cabang Kemang', 'KMG-02');

        $owner = $this->createUser($tenant, 'Owner', [$store1->id, $store2->id]);
        $this->actingAs($owner);

        // 1. Create table '01' in Store 1 -> SUCCESS
        session(['active_store_id' => $store1->id]);
        app(TenantContext::class)->setTenantId($tenant->id);
        app(TenantContext::class)->setStoreId($store1->id);

        $resp1 = $this->post(route('tables.store'), [
            'table_name' => '01',
            'capacity' => 4,
            'area' => 'Indoor',
            'operational_status' => 'available',
            'is_active' => 1,
        ]);
        $resp1->assertRedirect(route('tables.index'));
        $this->assertDatabaseHas('tables', [
            'tenant_id' => $tenant->id,
            'store_id' => $store1->id,
            'table_name' => '01',
        ]);

        // 2. Switch to Store 2 and create table '01' -> SUCCESS
        session(['active_store_id' => $store2->id]);
        app(TenantContext::class)->setStoreId($store2->id);

        $resp2 = $this->post(route('tables.store'), [
            'table_name' => '01',
            'capacity' => 4,
            'area' => 'Indoor',
            'operational_status' => 'available',
            'is_active' => 1,
        ]);
        $resp2->assertRedirect(route('tables.index'));
        $this->assertDatabaseHas('tables', [
            'tenant_id' => $tenant->id,
            'store_id' => $store2->id,
            'table_name' => '01',
        ]);

        // 3. Try to create duplicate table '01' in Store 2 again -> REJECTED with 422
        $respDuplicate = $this->post(route('tables.store'), [
            'table_name' => '01',
            'capacity' => 6,
            'area' => 'Outdoor',
            'operational_status' => 'available',
            'is_active' => 1,
        ]);
        $respDuplicate->assertSessionHasErrors(['table_name']);
    }
}
