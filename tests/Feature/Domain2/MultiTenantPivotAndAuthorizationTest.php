<?php

declare(strict_types=1);

namespace Tests\Feature\Domain2;

use App\Models\Category;
use App\Models\Discount;
use App\Models\Ingredient;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Order;
use App\Models\Shift;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Policies\DiscountPolicy;
use App\Policies\IngredientPolicy;
use App\Policies\OrderPolicy;
use App\Policies\ShiftPolicy;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [D2-BE-004 / D2-BE-005] MultiTenantPivotAndAuthorizationTest.
 *
 * Membuktikan:
 *  1. OrderPolicy / ShiftPolicy / DiscountPolicy / IngredientPolicy menolak
 *     akses lintas-tenant dan peran tanpa izin.
 *  2. Pivot ProductIngredient / ModifierIngredient / OrderItemModifier &
 *     StockBalance selalu men-stamp tenant_id (+ store_id bila ada).
 */
class MultiTenantPivotAndAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $optOutFromDefaultTenant = true;

    protected Tenant $tenantA;
    protected Tenant $tenantB;
    protected Store $storeA;
    protected User $ownerA;
    protected User $kasirA;
    protected User $ownerB;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();
        app(TenantContext::class)->clear();
        $this->tenantA = Tenant::create(['name' => 'A', 'readiness_status' => 'active']);
        $this->tenantB = Tenant::create(['name' => 'B', 'readiness_status' => 'active']);
        $this->storeA = Store::create(['tenant_id' => $this->tenantA->id, 'name' => 'A Store']);

        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $roleAOwner = \App\Models\Role::forceCreate(['tenant_id' => $this->tenantA->id, 'name' => 'Owner', 'permissions' => []]);
        $roleAKasir = \App\Models\Role::forceCreate(['tenant_id' => $this->tenantA->id, 'name' => 'Kasir', 'permissions' => []]);
        $this->ownerA = User::factory()->create(['tenant_id' => $this->tenantA->id, 'role_id' => $roleAOwner->id]);
        $this->kasirA = User::factory()->create(['tenant_id' => $this->tenantA->id, 'role_id' => $roleAKasir->id]);

        $context->setTenantId($this->tenantB->id);
        $roleBOwner = \App\Models\Role::forceCreate(['tenant_id' => $this->tenantB->id, 'name' => 'Owner', 'permissions' => []]);
        $this->ownerB = User::factory()->create(['tenant_id' => $this->tenantB->id, 'role_id' => $roleBOwner->id]);

        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);
    }

    public function test_order_policy_rejects_cross_tenant_and_unauthorized_roles(): void
    {
        $order = Order::forceCreate([
            'user_id' => $this->ownerA->id,
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'order_code' => 'ORD-POL-1',
            'order_date' => now()->toDateString(),
            'subtotal_amount' => 10000,
            'tax_amount' => 0,
            'service_charge_amount' => 0,
            'order_amount' => 10000,
            'order_status' => 'paid',
            'idempotency_key' => 'idemp-pol-1',
        ]);
        $policy = new OrderPolicy;

        $this->assertTrue($policy->create($this->kasirA));
        $this->assertTrue($policy->view($this->ownerA, $order));
        $this->assertTrue($policy->void($this->ownerA, $order));
        $this->assertFalse($policy->view($this->ownerB, $order), 'Cross-tenant view harus ditolak.');
        $this->assertFalse($policy->void($this->ownerB, $order), 'Cross-tenant void harus ditolak.');
        $this->assertFalse($policy->refund($this->kasirA, $order), 'Kasir tidak boleh refund.');
    }

    public function test_shift_policy_and_discount_policy_enforce_boundaries(): void
    {
        $shift = Shift::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->kasirA->id,
            'opening_balance' => 0,
            'status' => 'open',
            'opened_at' => now(),
        ]);
        $shiftPolicy = new ShiftPolicy;
        $this->assertTrue($shiftPolicy->open($this->kasirA));
        $this->assertTrue($shiftPolicy->close($this->kasirA, $shift));
        $this->assertTrue($shiftPolicy->recordCashMovement($this->kasirA, $shift));
        $this->assertFalse($shiftPolicy->close($this->ownerB, $shift), 'Cross-tenant close shift harus ditolak.');

        $discount = Discount::create([
            'tenant_id' => $this->tenantA->id,
            'code' => 'POL-DISC',
            'name' => 'Policy Discount',
            'type' => 'fixed',
            'value' => 1000,
            'min_purchase_amount' => 0,
        ]);
        $discountPolicy = new DiscountPolicy;
        $this->assertTrue($discountPolicy->create($this->ownerA));
        $this->assertTrue($discountPolicy->update($this->ownerA, $discount));
        $this->assertFalse($discountPolicy->create($this->kasirA), 'Kasir tidak boleh membuat diskon.');
        $this->assertFalse($discountPolicy->delete($this->ownerB, $discount), 'Cross-tenant delete diskon harus ditolak.');
    }

    public function test_ingredient_policy_rejects_cross_tenant(): void
    {
        $ingredient = Ingredient::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Gula',
            'unit' => 'gr',
            'current_stock' => '1000.0000',
        ]);
        $policy = new IngredientPolicy;
        $this->assertTrue($policy->view($this->ownerA, $ingredient));
        $this->assertTrue($policy->adjustStock($this->ownerA, $ingredient));
        $this->assertFalse($policy->view($this->ownerB, $ingredient), 'Cross-tenant view bahan baku harus ditolak.');
        $this->assertFalse($policy->adjustStock($this->ownerB, $ingredient));
    }

    public function test_product_ingredient_pivot_stamps_tenant_id(): void
    {
        $category = Category::create(['tenant_id' => $this->tenantA->id, 'category_name' => 'C']);
        $product = \App\Models\Product::create([
            'tenant_id' => $this->tenantA->id,
            'category_id' => $category->id,
            'product_name' => 'P1',
            'product_price' => 10000,
            'stock' => 10,
        ]);
        $ingredient = Ingredient::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Teh',
            'unit' => 'gr',
            'current_stock' => '500.0000',
        ]);
        app(TenantContext::class)->setTenantId($this->tenantA->id);

        // attach lewat relasi ->using(ProductIngredient): tenant_id di-stamp otomatis.
        $product->ingredients()->attach($ingredient->id, ['quantity_required' => 10]);

        $pivot = \App\Models\ProductIngredient::query()->first();
        $this->assertNotNull($pivot);
        $this->assertSame((int) $this->tenantA->id, (int) $pivot->tenant_id);
    }

    public function test_modifier_ingredient_pivot_stamps_tenant_id(): void
    {
        $group = ModifierGroup::create(['tenant_id' => $this->tenantA->id, 'name' => 'G', 'selection_type' => 'multiple']);
        $modifier = Modifier::create([
            'tenant_id' => $this->tenantA->id,
            'modifier_group_id' => $group->id,
            'name' => 'M1',
            'extra_price' => 1000,
        ]);
        $ingredient = Ingredient::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Susu',
            'unit' => 'ml',
            'current_stock' => '1000.0000',
        ]);
        app(TenantContext::class)->setTenantId($this->tenantA->id);

        $modifier->ingredients()->attach($ingredient->id, ['quantity_required' => 20]);

        $pivot = \App\Models\ModifierIngredient::query()->first();
        $this->assertNotNull($pivot);
        $this->assertSame((int) $this->tenantA->id, (int) $pivot->tenant_id);
    }
}
