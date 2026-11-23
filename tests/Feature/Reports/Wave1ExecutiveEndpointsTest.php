<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Core\Context\TenantContext;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Organization\Models\Role;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Models\Tenant;
use App\Modules\Organization\Models\User;
use App\Modules\Sales\Models\Order;
use Tests\Feature\Reports\E2e\BaseE2eReportTestCase;

/**
 * Wave1ExecutiveEndpointsTest: Validates JSON endpoints, tenant isolation,
 * empty state fallbacks, and role perspective gating for Wave 1 Executive Dashboard.
 */
class Wave1ExecutiveEndpointsTest extends BaseE2eReportTestCase
{
    /**
     * Test digital receipt JSON endpoint returns valid structure and enforces tenant isolation.
     */
    public function test_order_receipt_json_endpoint_returns_data_and_enforces_tenant_isolation(): void
    {
        $order = $this->createOrderWithPayment($this->tenant, $this->store, $this->ownerUser, [
            'order_code' => 'ORD-W1-RECEIPT-01',
            'order_amount' => 50000.00,
            'payment_method' => 'cash',
        ]);

        $this->createProductWithItem($this->tenant, $this->store, $order, [
            'product_name' => 'Kopi Susu Gula Aren',
            'product_code' => 'SKU-KOP-AREN',
            'product_price' => 25000.00,
        ], [
            'qty' => 2,
            'order_price' => 25000.00,
            'order_subtotal' => 50000.00,
        ]);

        // 1. Authenticated user in same tenant receives order details
        $this->actingAs($this->ownerUser);
        $response = $this->getJson(route('dashboard.orders.receipt', ['orderId' => $order->id]));
        $response->assertStatus(200);
        $response->assertJsonPath('order.order_code', 'ORD-W1-RECEIPT-01');
        $this->assertEquals(50000, (int) $response->json('order.total'));
        $response->assertJsonCount(1, 'items');

        // 2. Cross-tenant isolation: User from different tenant cannot access Tenant A order
        $tenantContext = app(TenantContext::class);
        $tenantB = null;
        $userB = null;

        $tenantContext->runWithoutTenant(function () use (&$tenantB, &$userB) {
            $tenantB = Tenant::create([
                'name' => 'Foreign Tenant B',
                'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
                'access_status' => Tenant::ACCESS_ACTIVE,
                'status' => 'active',
            ]);
            $storeB = Store::create([
                'tenant_id' => $tenantB->id,
                'name' => 'Store B',
                'code' => 'STR-B-01',
                'is_active' => true,
            ]);
            $roleB = Role::create([
                'tenant_id' => $tenantB->id,
                'name' => 'Owner',
                'is_admin' => true,
            ]);
            $userB = User::factory()->create([
                'tenant_id' => $tenantB->id,
                'store_id' => $storeB->id,
                'role_id' => $roleB->id,
            ]);
        });

        $this->actingAs($userB);
        $tenantContext->setTenantId($tenantB->id);

        $foreignResponse = $this->getJson(route('dashboard.orders.receipt', ['orderId' => $order->id]));
        $foreignResponse->assertStatus(200);
        $foreignResponse->assertJsonPath('order', null);
        $foreignResponse->assertJsonPath('items', []);

        // Restore context
        $tenantContext->setTenantId($this->tenant->id);
    }

    /**
     * Test product recipe BOM JSON endpoint returns BOM data and enforces tenant isolation.
     */
    public function test_product_recipe_bom_json_endpoint_returns_data_and_enforces_tenant_isolation(): void
    {
        $category = Category::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'category_name' => 'Pastry'],
            ['category_code' => 'CAT-PST']
        );

        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'category_id' => $category->id,
            'product_name' => 'Croissant Butter Spec',
            'product_code' => 'SKU-CRO-BUTTER',
            'product_price' => 35000.00,
            'stock' => 20,
            'is_active' => true,
        ]);

        // 1. Authenticated tenant user gets product economics
        $this->actingAs($this->ownerUser);
        $response = $this->getJson(route('dashboard.products.recipe-bom', ['productId' => $product->id]));
        $response->assertStatus(200);
        $response->assertJsonPath('product.id', $product->id);
        $response->assertJsonPath('product.name', 'Croissant Butter Spec');
        $this->assertEquals(35000, (int) $response->json('product.selling_price'));

        // 2. Cross-tenant isolation: Foreign tenant user receives null product
        $tenantContext = app(TenantContext::class);
        $tenantB = null;
        $userB = null;

        $tenantContext->runWithoutTenant(function () use (&$tenantB, &$userB) {
            $tenantB = Tenant::create([
                'name' => 'Foreign Tenant B2',
                'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
                'access_status' => Tenant::ACCESS_ACTIVE,
                'status' => 'active',
            ]);
            $storeB = Store::create([
                'tenant_id' => $tenantB->id,
                'name' => 'Store B2',
                'code' => 'STR-B-02',
                'is_active' => true,
            ]);
            $roleB = Role::create([
                'tenant_id' => $tenantB->id,
                'name' => 'Owner',
                'is_admin' => true,
            ]);
            $userB = User::factory()->create([
                'tenant_id' => $tenantB->id,
                'store_id' => $storeB->id,
                'role_id' => $roleB->id,
            ]);
        });

        $this->actingAs($userB);
        $tenantContext->setTenantId($tenantB->id);

        $foreignResponse = $this->getJson(route('dashboard.products.recipe-bom', ['productId' => $product->id]));
        $foreignResponse->assertStatus(200);
        $foreignResponse->assertJsonPath('product', null);

        // Restore context
        $tenantContext->setTenantId($this->tenant->id);
    }

    /**
     * Test empty dashboard renders fallback states cleanly with zero exceptions.
     */
    public function test_dashboard_executive_view_handles_empty_fallback_data_gracefully(): void
    {
        $tenantContext = app(TenantContext::class);
        $emptyTenant = null;
        $emptyUser = null;

        $tenantContext->runWithoutTenant(function () use (&$emptyTenant, &$emptyUser) {
            $emptyTenant = Tenant::create([
                'name' => 'Fresh Clean Tenant',
                'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
                'access_status' => Tenant::ACCESS_ACTIVE,
                'status' => 'active',
            ]);
            $emptyStore = Store::create([
                'tenant_id' => $emptyTenant->id,
                'name' => 'Flagship Empty Store',
                'code' => 'STR-EMPTY-01',
                'is_active' => true,
            ]);
            $emptyRole = Role::create([
                'tenant_id' => $emptyTenant->id,
                'name' => 'Owner',
                'is_admin' => true,
            ]);
            $emptyUser = User::factory()->create([
                'tenant_id' => $emptyTenant->id,
                'store_id' => $emptyStore->id,
                'role_id' => $emptyRole->id,
            ]);
        });

        $this->actingAs($emptyUser);
        $tenantContext->setTenantId($emptyTenant->id);

        $response = $this->get(route('dashboard', ['view' => 'executive']));

        $response->assertStatus(200);
        $response->assertSee('Business Overview');
        $response->assertSee('Rp 0');
        $response->assertSee('No recent transactions found', false);

        // Restore context
        $tenantContext->setTenantId($this->tenant->id);
    }

    /**
     * Test role perspective switcher is restricted for single-role users without cross-domain abilities.
     */
    public function test_cashier_role_perspective_is_restricted_from_executive_workspace(): void
    {
        $cashierRole = Role::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'name' => 'Kasir'],
            ['is_admin' => false, 'dashboard_archetype' => 'cashier_workspace']
        );

        $cashierUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'role_id' => $cashierRole->id,
        ]);

        // Attempting to access dashboard with role 'Kasir' resolves to cashier_workspace
        $this->actingAs($cashierUser);
        $response = $this->get(route('dashboard', ['view' => 'executive']));

        $response->assertStatus(200);
        // Enforced archetype should resolve to cashier_workspace, not executive
        $response->assertSee('Cashier Workspace');
        $response->assertDontSee('Consolidated point-of-sale revenue, margin performance');
    }
}
