<?php

namespace Tests\Feature;

use App\Livewire\DiscountManager;
use App\Livewire\Inventory\IngredientManager;
use App\Models\Discount;
use App\Models\Ingredient;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class LivewireTenancyIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_ingredient_manager_search_does_not_leak_other_tenant_data()
    {
        app(TenantContext::class)->runWithoutTenant(function () {
            $this->tenant1 = Tenant::forceCreate(['name' => 'T1']);
            $this->store1 = Store::forceCreate(['tenant_id' => $this->tenant1->id, 'name' => 'S1']);
            $this->role1 = Role::forceCreate(['tenant_id' => $this->tenant1->id, 'name' => 'Owner', 'is_active' => true]);
            $this->user1 = User::forceCreate([
                'tenant_id' => $this->tenant1->id,
                'role_id' => $this->role1->id,
                'name' => 'U1',
                'email' => 'u1@test.com',
                'password' => bcrypt('password'),
                'is_active' => true,
            ]);

            $this->tenant2 = Tenant::forceCreate(['name' => 'T2']);
            $this->store2 = Store::forceCreate(['tenant_id' => $this->tenant2->id, 'name' => 'S2']);

            // Ingredient for Tenant 2
            Ingredient::forceCreate([
                'tenant_id' => $this->tenant2->id,
                'ingredient_code' => 'SECRET-T2',
                'name' => 'Tenant 2 Ingredient',
                'unit' => 'kg',
                'current_stock' => 10,
                'is_active' => true,
            ]);

            DB::table('user_stores')->insert([
                'tenant_id' => $this->tenant1->id,
                'user_id' => $this->user1->id,
                'store_id' => $this->store1->id,
            ]);
        });

        // First, clear the singleton context so it represents a fresh request
        app(TenantContext::class)->clear();

        // Login as User 1
        $this->actingAs($this->user1);
        session(['active_store_id' => $this->store1->id]);

        Livewire::test(IngredientManager::class)
            ->set('search', 'SECRET-T2')
            ->assertDontSee('Tenant 2 Ingredient');
    }

    public function test_discount_manager_search_does_not_leak_other_tenant_data()
    {
        app(TenantContext::class)->runWithoutTenant(function () {
            $this->tenant1 = Tenant::forceCreate(['name' => 'T1']);
            $this->store1 = Store::forceCreate(['tenant_id' => $this->tenant1->id, 'name' => 'S1']);
            $this->role1 = Role::forceCreate(['tenant_id' => $this->tenant1->id, 'name' => 'Owner', 'is_active' => true]);
            $this->user1 = User::forceCreate([
                'tenant_id' => $this->tenant1->id,
                'role_id' => $this->role1->id,
                'name' => 'U1',
                'email' => 'u1@test.com',
                'password' => bcrypt('password'),
                'is_active' => true,
            ]);

            $this->tenant2 = Tenant::forceCreate(['name' => 'T2']);

            // Discount for Tenant 2
            Discount::forceCreate([
                'tenant_id' => $this->tenant2->id,
                'name' => 'Tenant 2 Promo',
                'code' => 'PROMO-T2',
                'type' => 'percentage',
                'value' => 10,
                'min_purchase_amount' => 0,
                'is_active' => true,
            ]);

            DB::table('user_stores')->insert([
                'tenant_id' => $this->tenant1->id,
                'user_id' => $this->user1->id,
                'store_id' => $this->store1->id,
            ]);
        });

        // First, clear the singleton context so it represents a fresh request
        app(TenantContext::class)->clear();

        // Login as User 1
        $this->actingAs($this->user1);
        session(['active_store_id' => $this->store1->id]);

        Livewire::test(DiscountManager::class)
            ->set('search', 'PROMO-T2')
            ->assertDontSee('Tenant 2 Promo');
    }
}
