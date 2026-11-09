<?php

declare(strict_types=1);

namespace Tests\Feature\Restaurant;

use App\Models\Role;
use App\Models\Store;
use App\Models\Table;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\TestCase;

class TableAuthorizationTest extends TestCase
{
    use DatabaseTransactions;

    protected bool $optOutFromDefaultTenant = true;

    protected Tenant $tenant;
    protected Store $store1;
    protected Store $store2;
    protected User $owner;
    protected User $managerStore1;
    protected User $barista;
    protected Table $tableStore1;
    protected Table $tableStore2;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();
        app(TenantContext::class)->clear();

        $this->tenant = Tenant::create([
            'name' => 'Restaurant Auth Tenant',
            'slug' => 'restaurant-auth-'.uniqid(),
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'subscription_status' => Tenant::SUBSCRIPTION_TRIAL,
            'subscription_plan' => 'standard_trial',
            'country_code' => 'ID',
        ]);

        Artisan::call('tenant:provision-system-users', ['tenant' => $this->tenant->id]);

        $this->store1 = Store::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Branch Downtown',
            'code' => 'DWN-01',
            'timezone' => 'Asia/Jakarta',
            'business_date_cutoff' => '00:00',
            'default_channel' => 'dine_in',
            'is_active' => true,
        ]);

        $this->store2 = Store::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Branch Uptown',
            'code' => 'UPT-01',
            'timezone' => 'Asia/Jakarta',
            'business_date_cutoff' => '00:00',
            'default_channel' => 'dine_in',
            'is_active' => true,
        ]);

        $roleOwner = Role::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'name' => 'Owner'],
            ['permissions' => ['*'], 'is_active' => true]
        );

        $roleManager = Role::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'name' => 'Manager'],
            ['permissions' => ['tables.manage'], 'is_active' => true]
        );

        $roleBarista = Role::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'name' => 'Barista'],
            ['permissions' => ['pos.order'], 'is_active' => true]
        );

        $this->owner = User::create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $roleOwner->id,
            'name' => 'Owner User',
            'email' => 'owner-'.uniqid().'@test.com',
            'password' => bcrypt('password123'),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $this->owner->stores()->sync([
            $this->store1->id => ['tenant_id' => $this->tenant->id],
            $this->store2->id => ['tenant_id' => $this->tenant->id],
        ]);

        $this->managerStore1 = User::create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $roleManager->id,
            'name' => 'Manager Store 1',
            'email' => 'mgr1-'.uniqid().'@test.com',
            'password' => bcrypt('password123'),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        // Only assigned to Store 1
        $this->managerStore1->stores()->sync([
            $this->store1->id => ['tenant_id' => $this->tenant->id],
        ]);

        $this->barista = User::create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $roleBarista->id,
            'name' => 'Barista User',
            'email' => 'barista-'.uniqid().'@test.com',
            'password' => bcrypt('password123'),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $this->barista->stores()->sync([
            $this->store1->id => ['tenant_id' => $this->tenant->id],
        ]);

        $this->tableStore1 = Table::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store1->id,
            'table_name' => 'T-D1',
            'table_code' => 'TBL-D1',
            'capacity' => 4,
            'area' => 'Indoor',
            'is_active' => true,
            'operational_status' => 'available',
            'qr_token' => bin2hex(random_bytes(32)),
            'qr_token_version' => 1,
            'secure_token' => (string) Str::uuid(),
        ]);

        $this->tableStore2 = Table::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store2->id,
            'table_name' => 'T-U1',
            'table_code' => 'TBL-U1',
            'capacity' => 6,
            'area' => 'Outdoor',
            'is_active' => true,
            'operational_status' => 'available',
            'qr_token' => bin2hex(random_bytes(32)),
            'qr_token_version' => 1,
            'secure_token' => (string) Str::uuid(),
        ]);
    }

    public function test_unauthorized_role_receives_403_on_table_management(): void
    {
        $this->actingAs($this->barista);
        session(['active_store_id' => $this->store1->id]);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(TenantContext::class)->setStoreId($this->store1->id);

        // Barista is blocked by role middleware or policy create
        $resp = $this->get(route('tables.create'));
        $this->assertEquals(403, $resp->getStatusCode());

        $resp = $this->post(route('tables.store'), [
            'store_id' => $this->store1->id,
            'table_name' => 'T-Hack',
            'capacity' => 2,
            'area' => 'Indoor',
        ]);
        $this->assertEquals(403, $resp->getStatusCode());
    }

    public function test_table_policy_strictly_denies_cross_store_manager(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $this->managerStore1->load('role');
        $this->owner->load('role');

        // Direct TablePolicy evaluation proving DEF-10 authorization rules:
        // Manager 1 cannot view, update, or delete Store 2's table.
        $this->assertFalse(Gate::forUser($this->managerStore1)->allows('view', $this->tableStore2));
        $this->assertFalse(Gate::forUser($this->managerStore1)->allows('update', $this->tableStore2));
        $this->assertFalse(Gate::forUser($this->managerStore1)->allows('delete', $this->tableStore2));

        // Manager 1 CAN view, update, and delete Store 1's table when active store is Store 1.
        app(TenantContext::class)->setStoreId($this->store1->id);
        $this->assertTrue(Gate::forUser($this->managerStore1)->allows('view', $this->tableStore1));
        $this->assertTrue(Gate::forUser($this->managerStore1)->allows('update', $this->tableStore1));
        $this->assertTrue(Gate::forUser($this->managerStore1)->allows('delete', $this->tableStore1));

        // Owner CAN manage tables across all stores (when active context matches the store)
        app(TenantContext::class)->setStoreId($this->store1->id);
        $this->assertTrue(Gate::forUser($this->owner)->allows('view', $this->tableStore1));
        $this->assertTrue(Gate::forUser($this->owner)->allows('update', $this->tableStore1));

        app(TenantContext::class)->setStoreId($this->store2->id);
        $this->assertTrue(Gate::forUser($this->owner)->allows('view', $this->tableStore2));
        $this->assertTrue(Gate::forUser($this->owner)->allows('update', $this->tableStore2));
    }

    public function test_cross_store_manager_cannot_mutate_table_in_another_store(): void
    {
        // Manager 1 belongs only to Store 1, and attempts to target Store 2's table via HTTP.
        $this->actingAs($this->managerStore1);
        session(['active_store_id' => $this->store1->id]);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(TenantContext::class)->setStoreId($this->store1->id);

        // 1. Attempt to edit Table in Store 2 -> Access denied (403 or 404 fail-closed)
        $editResp = $this->get(route('tables.edit', $this->tableStore2->id));
        $this->assertTrue(in_array($editResp->getStatusCode(), [403, 404], true));

        // 2. Attempt to update Table in Store 2 -> Access denied
        $updateResp = $this->put(route('tables.update', $this->tableStore2->id), [
            'table_name' => 'T-Hijack',
            'capacity' => 10,
            'area' => 'Indoor',
            'operational_status' => 'available',
            'is_active' => 1,
        ]);
        $this->assertTrue(in_array($updateResp->getStatusCode(), [403, 404], true));
        $this->assertDatabaseMissing('tables', [
            'id' => $this->tableStore2->id,
            'table_name' => 'T-Hijack',
        ]);

        // 3. Attempt to delete Table in Store 2 -> Access denied
        $delResp = $this->delete(route('tables.destroy', $this->tableStore2->id));
        $this->assertTrue(in_array($delResp->getStatusCode(), [403, 404], true));
        $this->assertNull($this->tableStore2->fresh()->deleted_at);

        // 4. Attempt to rotate QR code on Table in Store 2 -> Access denied
        $rotateResp = $this->post(route('tables.rotate-qr', $this->tableStore2->id));
        $this->assertTrue(in_array($rotateResp->getStatusCode(), [403, 404], true));

        // 5. Attempt to release Table in Store 2 -> Access denied
        $releaseResp = $this->post(route('tables.release', $this->tableStore2->id));
        $this->assertTrue(in_array($releaseResp->getStatusCode(), [403, 404], true));
    }

    public function test_store_manager_can_manage_own_store_table(): void
    {
        $this->actingAs($this->managerStore1);
        session(['active_store_id' => $this->store1->id]);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(TenantContext::class)->setStoreId($this->store1->id);

        // Manager of Store 1 can edit Table in Store 1
        $editResp = $this->get(route('tables.edit', $this->tableStore1->id));
        $this->assertEquals(200, $editResp->getStatusCode());

        // Manager of Store 1 can update Table in Store 1
        $updateResp = $this->put(route('tables.update', $this->tableStore1->id), [
            'table_name' => 'T-D1-Up',
            'capacity' => 6,
            'area' => 'Indoor',
            'operational_status' => 'available',
            'is_active' => 1,
        ]);
        $updateResp->assertRedirect(route('tables.index'));
        $this->assertDatabaseHas('tables', [
            'id' => $this->tableStore1->id,
            'table_name' => 'T-D1-Up',
            'capacity' => 6,
        ]);

        // Manager of Store 1 can rotate QR
        $rotateResp = $this->post(route('tables.rotate-qr', $this->tableStore1->id));
        $rotateResp->assertRedirect(route('tables.index'));

        // Manager of Store 1 can release table
        $releaseResp = $this->post(route('tables.release', $this->tableStore1->id));
        $releaseResp->assertRedirect(route('tables.index'));
    }

    public function test_owner_can_manage_tables_in_all_stores(): void
    {
        $this->actingAs($this->owner);
        session(['active_store_id' => $this->store1->id]);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(TenantContext::class)->setStoreId($this->store1->id);

        // Owner can access Table in Store 1
        $editResp1 = $this->get(route('tables.edit', $this->tableStore1->id));
        $this->assertEquals(200, $editResp1->getStatusCode());

        // When switching active store context to Store 2, owner can access Table in Store 2
        session(['active_store_id' => $this->store2->id]);
        app(TenantContext::class)->setStoreId($this->store2->id);

        $editResp2 = $this->get(route('tables.edit', $this->tableStore2->id));
        $this->assertEquals(200, $editResp2->getStatusCode());

        $updateResp = $this->put(route('tables.update', $this->tableStore2->id), [
            'table_name' => 'T-U1-Own',
            'capacity' => 8,
            'area' => 'Outdoor',
            'operational_status' => 'available',
            'is_active' => 1,
        ]);
        $updateResp->assertRedirect(route('tables.index'));
        $this->assertDatabaseHas('tables', [
            'id' => $this->tableStore2->id,
            'table_name' => 'T-U1-Own',
        ]);
    }
}
