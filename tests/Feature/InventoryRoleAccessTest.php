<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

class InventoryRoleAccessTest extends TestCase
{
    use RefreshDatabase;
    use TwoTenantFixture;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();
        app(TenantContext::class)->runWithoutTenant(function () {
            $this->setupTwoTenants();
        });
    }

    public function test_inventory_role_can_access_ingredient_modules(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $role = Role::withoutTenantScope()->firstOrCreate(
            ['name' => 'Inventory', 'tenant_id' => $this->tenantA->id],
            ['is_active' => true]
        );

        $user = User::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'role_id' => $role->id,
        ]);
        $user->stores()->attach($this->storeA->id, ['tenant_id' => $this->tenantA->id]);

        $this->actingAs($user)
            ->withSession(['active_store_id' => $this->storeA->id])
            ->get(route('inventory.ingredients'))
            ->assertOk();

        $this->actingAs($user)
            ->withSession(['active_store_id' => $this->storeA->id])
            ->get(route('inventory.ledger'))
            ->assertOk();
    }
}
