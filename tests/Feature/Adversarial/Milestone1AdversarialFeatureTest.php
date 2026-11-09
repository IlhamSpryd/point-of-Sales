<?php

declare(strict_types=1);

namespace Tests\Feature\Adversarial;

use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\Table;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Retail\Models\ShelfLabel;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class Milestone1AdversarialFeatureTest extends TestCase
{
    use DatabaseTransactions;

    protected bool $optOutFromDefaultTenant = true;

    protected Tenant $tenantA;
    protected Tenant $tenantB;
    protected Store $storeA1;
    protected Store $storeA2;
    protected Store $storeB;
    protected Product $productA;
    protected Product $productB;
    protected User $ownerA;
    protected User $managerA1;
    protected User $cashierA;
    protected Table $tableA1;
    protected Table $tableA2;
    protected Table $tableB;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();
        app(TenantContext::class)->clear();

        // 1. Tenant A Setup
        $this->tenantA = Tenant::create([
            'name' => 'Adversarial Tenant A',
            'slug' => 'adv-tenant-a-'.uniqid(),
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'subscription_status' => Tenant::SUBSCRIPTION_TRIAL,
            'subscription_plan' => 'standard_trial',
            'country_code' => 'ID',
        ]);

        Artisan::call('tenant:provision-system-users', ['tenant' => $this->tenantA->id]);

        $this->storeA1 = Store::withoutTenantScope()->create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Store A1 Downtown',
            'code' => 'ADV-A1-'.uniqid(),
            'timezone' => 'Asia/Jakarta',
            'business_date_cutoff' => '00:00',
            'default_channel' => 'dine_in',
            'is_active' => true,
        ]);

        $this->storeA2 = Store::withoutTenantScope()->create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Store A2 Uptown',
            'code' => 'ADV-A2-'.uniqid(),
            'timezone' => 'Asia/Jakarta',
            'business_date_cutoff' => '00:00',
            'default_channel' => 'dine_in',
            'is_active' => true,
        ]);

        $catA = Category::withoutTenantScope()->create([
            'tenant_id' => $this->tenantA->id,
            'category_name' => 'Category Adv A',
        ]);

        $this->productA = Product::withoutTenantScope()->create([
            'tenant_id' => $this->tenantA->id,
            'category_id' => $catA->id,
            'product_name' => 'Product Adv A',
            'product_price' => 25000,
            'is_active' => true,
        ]);

        // Roles for Tenant A
        $roleOwner = Role::withoutTenantScope()->firstOrCreate(
            ['tenant_id' => $this->tenantA->id, 'name' => 'Owner'],
            ['permissions' => ['*'], 'is_active' => true]
        );

        $roleManager = Role::withoutTenantScope()->firstOrCreate(
            ['tenant_id' => $this->tenantA->id, 'name' => 'Manager'],
            ['permissions' => ['tables.manage'], 'is_active' => true]
        );

        $roleCashier = Role::withoutTenantScope()->firstOrCreate(
            ['tenant_id' => $this->tenantA->id, 'name' => 'Kasir'],
            ['permissions' => ['pos.order'], 'is_active' => true]
        );

        // Users for Tenant A
        $this->ownerA = User::create([
            'tenant_id' => $this->tenantA->id,
            'role_id' => $roleOwner->id,
            'name' => 'Owner Adv A',
            'email' => 'owner-adv-'.uniqid().'@test.com',
            'password' => bcrypt('password123'),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $this->ownerA->stores()->sync([
            $this->storeA1->id => ['tenant_id' => $this->tenantA->id],
            $this->storeA2->id => ['tenant_id' => $this->tenantA->id],
        ]);

        $this->managerA1 = User::create([
            'tenant_id' => $this->tenantA->id,
            'role_id' => $roleManager->id,
            'name' => 'Manager Store A1 Only',
            'email' => 'mgr-a1-'.uniqid().'@test.com',
            'password' => bcrypt('password123'),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        // Strictly assigned ONLY to Store A1
        $this->managerA1->stores()->sync([
            $this->storeA1->id => ['tenant_id' => $this->tenantA->id],
        ]);

        $this->cashierA = User::create([
            'tenant_id' => $this->tenantA->id,
            'role_id' => $roleCashier->id,
            'name' => 'Cashier Adv A',
            'email' => 'cashier-adv-'.uniqid().'@test.com',
            'password' => bcrypt('password123'),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $this->cashierA->stores()->sync([
            $this->storeA1->id => ['tenant_id' => $this->tenantA->id],
        ]);

        // Tables for Tenant A
        $this->tableA1 = Table::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA1->id,
            'table_name' => 'ADV-T-A1',
            'table_code' => 'TBL-ADV-A1',
            'capacity' => 4,
            'area' => 'Indoor',
            'is_active' => true,
            'operational_status' => 'available',
            'qr_token' => bin2hex(random_bytes(32)),
            'qr_token_version' => 1,
            'secure_token' => (string) Str::uuid(),
        ]);

        $this->tableA2 = Table::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA2->id,
            'table_name' => 'ADV-T-A2',
            'table_code' => 'TBL-ADV-A2',
            'capacity' => 6,
            'area' => 'Terrace',
            'is_active' => true,
            'operational_status' => 'available',
            'qr_token' => bin2hex(random_bytes(32)),
            'qr_token_version' => 1,
            'secure_token' => (string) Str::uuid(),
        ]);

        // 2. Tenant B Setup
        $this->tenantB = Tenant::create([
            'name' => 'Adversarial Tenant B',
            'slug' => 'adv-tenant-b-'.uniqid(),
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'subscription_status' => Tenant::SUBSCRIPTION_TRIAL,
            'subscription_plan' => 'standard_trial',
            'country_code' => 'ID',
        ]);

        Artisan::call('tenant:provision-system-users', ['tenant' => $this->tenantB->id]);

        $this->storeB = Store::withoutTenantScope()->create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Store B Outpost',
            'code' => 'ADV-B-'.uniqid(),
            'timezone' => 'Asia/Jakarta',
            'business_date_cutoff' => '00:00',
            'default_channel' => 'dine_in',
            'is_active' => true,
        ]);

        $catB = Category::withoutTenantScope()->create([
            'tenant_id' => $this->tenantB->id,
            'category_name' => 'Category Adv B',
        ]);

        $this->productB = Product::withoutTenantScope()->create([
            'tenant_id' => $this->tenantB->id,
            'category_id' => $catB->id,
            'product_name' => 'Product Adv B',
            'product_price' => 35000,
            'is_active' => true,
        ]);

        $this->tableB = Table::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantB->id,
            'store_id' => $this->storeB->id,
            'table_name' => 'ADV-T-B1',
            'table_code' => 'TBL-ADV-B1',
            'capacity' => 2,
            'area' => 'Balcony',
            'is_active' => true,
            'operational_status' => 'available',
            'qr_token' => bin2hex(random_bytes(32)),
            'qr_token_version' => 1,
            'secure_token' => (string) Str::uuid(),
        ]);
    }

    /**
     * DEF-07 Empirical Challenge: Multi-Tenant ShelfLabel Adversarial Isolation.
     * Stress-tests:
     * - Tenant B queries cannot find Tenant A's ShelfLabel.
     * - Hostile update by Tenant B targeting Tenant A's ShelfLabel affects 0 rows.
     * - Hostile delete by Tenant B targeting Tenant A's ShelfLabel affects 0 rows.
     * - Hostile create with injected tenant_id is forced to active tenant_id by AssignsTenant.
     * - Unauthenticated context fails closed (0 records returned, 0 rows mutated).
     */
    public function test_shelf_label_multi_tenant_adversarial_isolation(): void
    {
        $context = app(TenantContext::class);

        // 1. Create ShelfLabel in Tenant A
        $context->setTenantId($this->tenantA->id);
        $labelA = ShelfLabel::create([
            'store_id' => $this->storeA1->id,
            'product_id' => $this->productA->id,
            'old_price' => '30000.00',
            'new_price' => '35000.00',
            'status' => ShelfLabel::STATUS_PENDING,
            'batch_number' => 'BATCH-A-ADV',
        ]);
        $this->assertNotNull($labelA->id);
        $this->assertSame($this->tenantA->id, $labelA->tenant_id);

        // 2. Switch context to Tenant B
        $context->setTenantId($this->tenantB->id);

        // Queries in Tenant B context
        $this->assertNull(ShelfLabel::find($labelA->id), 'Tenant B find() must return null for Tenant A record');
        $this->assertNull(ShelfLabel::where('id', $labelA->id)->first(), 'Tenant B where(id) must return null');
        $this->assertEquals(0, ShelfLabel::where('batch_number', 'BATCH-A-ADV')->count());
        $this->assertCount(0, ShelfLabel::all(), 'Tenant B all() must return empty collection');

        // Hostile cross-tenant update attempt: Tenant B attempts to alter price of Tenant A's label
        $affectedRowsUpdate = ShelfLabel::where('id', $labelA->id)->update([
            'new_price' => '999999.00',
        ]);
        $this->assertSame(0, $affectedRowsUpdate, 'Adversarial cross-tenant update MUST affect exactly 0 rows');

        // Verify in DB that labelA was NOT modified
        $freshLabelA = DB::table('shelf_labels')->where('id', $labelA->id)->first();
        $this->assertSame('35000.00', $freshLabelA->new_price, 'Tenant A ShelfLabel price must not be corrupted by Tenant B');

        // Hostile cross-tenant delete attempt: Tenant B attempts to delete Tenant A's label
        $affectedRowsDelete = ShelfLabel::where('id', $labelA->id)->delete();
        $this->assertSame(0, $affectedRowsDelete, 'Adversarial cross-tenant delete MUST affect exactly 0 rows');

        // Verify in DB that labelA was NOT deleted
        $existsInDb = DB::table('shelf_labels')->where('id', $labelA->id)->exists();
        $this->assertTrue($existsInDb, 'Tenant A ShelfLabel must not be deleted by Tenant B');

        // Hostile create attempt: Tenant B passes tenant_id => Tenant A
        // AssignsTenant strictly guards against cross-tenant spoofing and throws an exception
        try {
            $labelSpoof = ShelfLabel::create([
                'tenant_id' => $this->tenantA->id, // Malicious spoof attempt
                'store_id' => $this->storeB->id,
                'product_id' => $this->productB->id,
                'old_price' => '10000.00',
                'new_price' => '12000.00',
                'status' => ShelfLabel::STATUS_PENDING,
                'batch_number' => 'BATCH-SPOOF',
            ]);
            $this->assertSame(
                $this->tenantB->id,
                $labelSpoof->tenant_id,
                'AssignsTenant must force tenant_id to active Tenant B, discarding spoofed Tenant A'
            );
        } catch (\Throwable $e) {
            $this->assertStringContainsString('Cannot create record for tenant', $e->getMessage());
        }

        // 3. Unauthenticated context (simulate zero tenant context)
        $context->clear();

        $this->assertCount(0, ShelfLabel::all(), 'Unauthenticated query must return 0 records (fail closed)');
        $this->assertNull(ShelfLabel::find($labelA->id), 'Unauthenticated find must return null');
        $this->assertEquals(0, ShelfLabel::count(), 'Unauthenticated count must be 0');

        $unauthUpdate = ShelfLabel::where('id', $labelA->id)->update(['new_price' => '1.00']);
        $this->assertSame(0, $unauthUpdate, 'Unauthenticated update must affect 0 rows');

        $unauthDelete = ShelfLabel::where('id', $labelA->id)->delete();
        $this->assertSame(0, $unauthDelete, 'Unauthenticated delete must affect 0 rows');
    }

    /**
     * DEF-10 Empirical Challenge: TableController & TablePolicy Adversarial Authorization.
     * Stress-tests:
     * - Cross-store Manager Attack: Manager of Store A1 attempting ANY operation on Store A2's table receives 403 Forbidden.
     * - Cashier Attack: Kasir attempting to access TableController endpoints receives 403 Forbidden.
     * - Cross-Tenant Attack: User attempting to mutate table in another tenant receives 403 or 404.
     */
    public function test_table_controller_adversarial_authorization(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA1->id);

        $this->managerA1->load('role');
        $this->cashierA->load('role');
        $this->ownerA->load('role');

        // --- ATTACK 1: Cross-Store Manager Mutation Attacks ---
        // Manager A1 is assigned ONLY to Store A1. Attempts to mutate Store A2's table.
        $this->actingAs($this->managerA1);
        session(['active_store_id' => $this->storeA1->id]);

        // 1.1 Direct Gate checks
        $this->assertFalse(
            Gate::forUser($this->managerA1)->allows('view', $this->tableA2),
            'Manager A1 must NOT be allowed to view Store A2 table'
        );
        $this->assertFalse(
            Gate::forUser($this->managerA1)->allows('update', $this->tableA2),
            'Manager A1 must NOT be allowed to update Store A2 table'
        );
        $this->assertFalse(
            Gate::forUser($this->managerA1)->allows('delete', $this->tableA2),
            'Manager A1 must NOT be allowed to delete Store A2 table'
        );

        // 1.2 HTTP attacks against Store A2's table (must fail closed with 403 Forbidden or 404 Not Found)
        // Show
        $showResp = $this->get(route('tables.show', $this->tableA2->id));
        $this->assertContains($showResp->getStatusCode(), [403, 404], 'GET show on other store table must fail closed with 403 or 404');

        // Edit
        $editResp = $this->get(route('tables.edit', $this->tableA2->id));
        $this->assertContains($editResp->getStatusCode(), [403, 404], 'GET edit on other store table must fail closed with 403 or 404');

        // Update
        $updateResp = $this->put(route('tables.update', $this->tableA2->id), [
            'table_name' => 'ADV-HIJACK-A2',
            'capacity' => 12,
            'area' => 'Terrace',
            'operational_status' => 'available',
            'is_active' => 1,
        ]);
        $this->assertContains($updateResp->getStatusCode(), [403, 404], 'PUT update on other store table must fail closed with 403 or 404');
        $this->assertDatabaseMissing('tables', ['id' => $this->tableA2->id, 'table_name' => 'ADV-HIJACK-A2']);

        // Destroy
        $destroyResp = $this->delete(route('tables.destroy', $this->tableA2->id));
        $this->assertContains($destroyResp->getStatusCode(), [403, 404], 'DELETE on other store table must fail closed with 403 or 404');
        $this->assertNull($this->tableA2->fresh()->deleted_at);

        // Rotate QR
        $rotateResp = $this->post(route('tables.rotate-qr', $this->tableA2->id));
        $this->assertContains($rotateResp->getStatusCode(), [403, 404], 'POST rotate-qr on other store table must fail closed with 403 or 404');

        // Release
        $releaseResp = $this->post(route('tables.release', $this->tableA2->id));
        $this->assertContains($releaseResp->getStatusCode(), [403, 404], 'POST release on other store table must fail closed with 403 or 404');

        // Regenerate Token
        $regenResp = $this->post(route('tables.regenerate-token', $this->tableA2->id));
        $this->assertContains($regenResp->getStatusCode(), [403, 404], 'POST regenerate-token on other store table must fail closed with 403 or 404');

        // --- ATTACK 2: Cashier Role Attacks ---
        // Cashier user attempts to access any table management route
        $this->actingAs($this->cashierA);

        // 2.1 Direct Gate checks
        $this->assertFalse(
            Gate::forUser($this->cashierA)->allows('create', Table::class),
            'Cashier must NOT be allowed to create tables'
        );
        $this->assertFalse(
            Gate::forUser($this->cashierA)->allows('update', $this->tableA1),
            'Cashier must NOT be allowed to update tables'
        );
        $this->assertFalse(
            Gate::forUser($this->cashierA)->allows('delete', $this->tableA1),
            'Cashier must NOT be allowed to delete tables'
        );

        // 2.2 HTTP attacks by Cashier
        $indexResp = $this->get(route('tables.index'));
        $this->assertEquals(403, $indexResp->getStatusCode(), 'Cashier GET index must return 403');

        $createResp = $this->get(route('tables.create'));
        $this->assertEquals(403, $createResp->getStatusCode(), 'Cashier GET create must return 403');

        $storeResp = $this->post(route('tables.store'), [
            'table_name' => 'ADV-CASHIER-TBL',
            'capacity' => 4,
            'area' => 'Indoor',
            'operational_status' => 'available',
        ]);
        $this->assertEquals(403, $storeResp->getStatusCode(), 'Cashier POST store must return 403');

        $cashierUpdateResp = $this->put(route('tables.update', $this->tableA1->id), [
            'table_name' => 'ADV-CASHIER-UPDATE',
            'capacity' => 8,
            'area' => 'Indoor',
            'operational_status' => 'available',
        ]);
        $this->assertEquals(403, $cashierUpdateResp->getStatusCode(), 'Cashier PUT update must return 403');

        $cashierDeleteResp = $this->delete(route('tables.destroy', $this->tableA1->id));
        $this->assertEquals(403, $cashierDeleteResp->getStatusCode(), 'Cashier DELETE must return 403');

        // --- ATTACK 3: Cross-Tenant Attack ---
        // Tenant A Owner attempts to access Tenant B's table
        $this->actingAs($this->ownerA);
        session(['active_store_id' => $this->storeA1->id]);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA1->id);

        $crossTenantResp = $this->get(route('tables.show', $this->tableB->id));
        // Must fail closed with 403 or 404
        $this->assertContains(
            $crossTenantResp->getStatusCode(),
            [403, 404],
            'Accessing another tenant table must return 403 Forbidden or 404 Not Found'
        );
    }

    /**
     * DEF-11 Empirical Challenge: Schema presence & integration validation of operational_units.
     * Stress-tests:
     * - operational_units table exists in test DB.
     * - All canonical columns are present with appropriate types.
     * - Store creation successfully interacts with operational units.
     */
    public function test_operational_units_schema_and_integration(): void
    {
        // 1. Table schema presence
        $this->assertTrue(
            Schema::hasTable('operational_units'),
            "Table 'operational_units' MUST exist in test database"
        );

        // 2. Canonical column presence
        $expectedColumns = [
            'id',
            'tenant_id',
            'store_id',
            'name',
            'code',
            'unit_type',
            'is_default',
            'is_active',
            'created_at',
            'updated_at',
        ];

        foreach ($expectedColumns as $col) {
            $this->assertTrue(
                Schema::hasColumn('operational_units', $col),
                "Column '{$col}' must exist on table 'operational_units'"
            );
        }

        // 3. Verify operational_unit created during store lifecycle
        $units = DB::table('operational_units')
            ->where('tenant_id', $this->tenantA->id)
            ->where('store_id', $this->storeA1->id)
            ->get();

        $this->assertNotEmpty(
            $units,
            'Operational units must be created and linked to newly provisioned store'
        );

        $defaultUnit = $units->firstWhere('is_default', 1);
        $this->assertNotNull($defaultUnit, 'A default operational unit must be designated');
        $this->assertSame($this->tenantA->id, $defaultUnit->tenant_id);
        $this->assertSame($this->storeA1->id, $defaultUnit->store_id);
    }
}
