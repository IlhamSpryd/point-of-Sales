<?php

declare(strict_types=1);

namespace Tests\Feature\Reports\E2e;

use App\Core\Context\TenantContext;
use App\Enums\OrderStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Organization\Models\Company;
use App\Modules\Organization\Models\Role;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Models\Tenant;
use App\Modules\Organization\Models\User;
use App\Modules\Reporting\Models\StoreTarget;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderItem;
use App\Modules\Sales\Models\Payment;
use App\Modules\Sales\Models\Shift;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class BaseE2eReportTestCase extends TestCase
{
    use DatabaseTransactions;

    protected Tenant $tenant;

    protected Store $store;

    protected Company $company;

    protected User $ownerUser;

    protected Role $ownerRole;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $tenantContext = app(TenantContext::class);
        $tenantContext->clear();

        $tenantContext->runWithoutTenant(function () {
            $this->tenant = $this->defaultTenant ?? Tenant::firstOrCreate(
                ['name' => 'Default E2E Tenant'],
                [
                    'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
                    'access_status' => Tenant::ACCESS_ACTIVE,
                    'status' => 'active',
                ]
            );

            $this->store = $this->defaultStore ?? Store::firstOrCreate(
                ['tenant_id' => $this->tenant->id, 'name' => 'Default E2E Store'],
                [
                    'code' => 'STR-DEF',
                    'is_active' => true,
                ]
            );

            $this->company = Company::firstOrCreate(
                ['tenant_id' => $this->tenant->id, 'company_code' => 'CMP-E2E-DEF'],
                [
                    'company_name' => 'E2E Testing Default Company',
                    'legal_name' => 'PT Apeiron E2E Testing',
                    'is_active' => true,
                    'is_default' => true,
                ]
            );

            $this->ownerRole = Role::firstOrCreate(
                ['name' => 'Owner', 'tenant_id' => $this->tenant->id],
                [
                    'role_code' => 'ROL-E2E-OWNER',
                    'permissions' => ['*'],
                    'dashboard_archetype' => 'executive',
                    'is_system_default' => true,
                    'is_active' => true,
                ]
            );

            $this->ownerUser = User::factory()->create([
                'tenant_id' => $this->tenant->id,
                'role_id' => $this->ownerRole->id,
                'email' => 'e2e.owner.'.Str::random(8).'@apeiron.test',
                'email_verified_at' => now(),
                'is_active' => true,
            ]);

            $this->ownerUser->stores()->syncWithoutDetaching([
                $this->store->id => ['tenant_id' => $this->tenant->id],
            ]);
        });

        $tenantContext->setTenantId($this->tenant->id);
        $tenantContext->setCompanyId($this->company->id);
        $tenantContext->setStoreId($this->store->id);
    }

    /**
     * Create an isolated tenant for testing.
     */
    protected function createTenant(array $attributes = []): Tenant
    {
        return Tenant::create(array_merge([
            'name' => 'E2E Tenant '.Str::random(8),
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'status' => 'active',
        ], $attributes));
    }

    /**
     * Create an isolated store for a given tenant.
     */
    protected function createStore(Tenant $tenant, array $attributes = []): Store
    {
        return Store::create(array_merge([
            'tenant_id' => $tenant->id,
            'name' => 'E2E Outlet '.Str::random(6),
            'code' => 'STR-'.strtoupper(Str::random(5)),
            'is_active' => true,
        ], $attributes));
    }

    /**
     * Create a user with a specific role, permissions, and store assignment.
     */
    protected function createUserWithRole(
        Tenant $tenant,
        string $roleName,
        array $permissions = ['*'],
        ?Store $store = null,
        string $archetype = 'executive',
        array $userAttributes = []
    ): User {
        $role = Role::firstOrCreate(
            ['name' => $roleName, 'tenant_id' => $tenant->id],
            [
                'role_code' => 'ROL-'.strtoupper(Str::slug($roleName)).'-'.Str::random(4),
                'permissions' => $permissions,
                'dashboard_archetype' => $archetype,
                'is_system_default' => false,
                'is_active' => true,
            ]
        );

        $user = User::factory()->create(array_merge([
            'tenant_id' => $tenant->id,
            'role_id' => $role->id,
            'email' => strtolower(Str::slug($roleName)).'.'.Str::random(8).'@apeiron.test',
            'email_verified_at' => now(),
            'is_active' => true,
        ], $userAttributes));

        if ($store) {
            $user->stores()->syncWithoutDetaching([
                $store->id => ['tenant_id' => $tenant->id],
            ]);
        }

        return $user;
    }

    /**
     * Create an order with an accompanying payment record.
     */
    protected function createOrderWithPayment(
        Tenant $tenant,
        Store $store,
        User $user,
        array $orderAttributes = [],
        array $paymentAttributes = []
    ): Order {
        $orderAmount = $orderAttributes['order_amount'] ?? 100000.00;
        $orderCode = $orderAttributes['order_code'] ?? ('ORD-E2E-'.strtoupper(Str::random(8)));
        $orderDate = $orderAttributes['order_date'] ?? Carbon::now('Asia/Jakarta')->toDateString();

        $order = Order::withoutGlobalScopes()->forceCreate(array_merge([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'user_id' => $user->id,
            'order_code' => $orderCode,
            'idempotency_key' => 'IDEMP-'.Str::random(12),
            'subtotal_amount' => $orderAmount,
            'discount_amount' => 0.00,
            'tax_amount' => 0.00,
            'order_amount' => $orderAmount,
            'order_date' => $orderDate,
            'order_status' => OrderStatus::Paid->value,
            'payment_method' => $orderAttributes['payment_method'] ?? 'cash',
            'created_at' => now(),
            'updated_at' => now(),
        ], $orderAttributes));

        Payment::withoutGlobalScopes()->forceCreate(array_merge([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'order_id' => $order->id,
            'payment_method' => $order->payment_method ?? 'cash',
            'amount' => $order->order_amount,
            'amount_tendered' => $order->order_amount,
            'change_amount' => 0.00,
            'reference_number' => 'REF-'.Str::random(10),
            'status' => 'captured',
            'idempotency_key' => 'PAY-IDEMP-'.Str::random(12),
            'created_at' => now(),
            'updated_at' => now(),
        ], $paymentAttributes));

        return $order;
    }

    /**
     * Create a shift record (open or closed).
     */
    protected function createShift(
        Tenant $tenant,
        Store $store,
        User $user,
        array $attributes = []
    ): Shift {
        return Shift::withoutGlobalScopes()->forceCreate(array_merge([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'user_id' => $user->id,
            'opened_at' => now()->subHours(4),
            'closed_at' => now(),
            'opening_balance' => 200000.00,
            'closing_balance' => 500000.00,
            'expected_cash' => 500000.00,
            'cash_difference' => 0.00,
            'status' => 'closed',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    /**
     * Create or update a store monthly target for KPI-PLN-01.
     */
    protected function createStoreTarget(
        Tenant $tenant,
        Store $store,
        string $period,
        float $targetSales,
        array $attributes = []
    ): StoreTarget {
        return StoreTarget::withoutGlobalScopes()->updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'store_id' => $store->id,
                'target_period' => $period,
            ],
            array_merge([
                'company_id' => $this->company->id ?? null,
                'target_net_sales' => $targetSales,
                'target_gross_margin_pct' => 45.0,
                'max_shrinkage_pct' => 1.5,
                'max_labor_cost_pct' => 20.0,
            ], $attributes)
        );
    }

    /**
     * Helper to create a product and optionally attach an order item.
     */
    protected function createProductWithItem(
        Tenant $tenant,
        Store $store,
        ?Order $order = null,
        array $productAttributes = [],
        array $itemAttributes = []
    ): Product {
        $sku = $productAttributes['product_code'] ?? ($productAttributes['sku'] ?? ('SKU-'.strtoupper(Str::random(6))));
        $name = $productAttributes['product_name'] ?? ($productAttributes['name'] ?? ('Product '.Str::random(5)));
        $price = $productAttributes['product_price'] ?? ($productAttributes['selling_price'] ?? ($productAttributes['price'] ?? 50000.00));
        $cost = $productAttributes['standard_cost'] ?? ($productAttributes['cost_price'] ?? 25000.00);

        unset($productAttributes['sku'], $productAttributes['name'], $productAttributes['selling_price'], $productAttributes['price'], $productAttributes['cost_price']);

        $categoryId = $productAttributes['category_id'] ?? (
            \Illuminate\Support\Facades\DB::table('categories')
                ->where('tenant_id', $tenant->id)
                ->where('category_name', 'General')
                ->value('id')
            ?? \Illuminate\Support\Facades\DB::table('categories')->insertGetId([
                'tenant_id' => $tenant->id,
                'category_name' => 'General',
                'category_code' => 'CAT-'.strtoupper(Str::random(4)),
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ])
        );

        $product = Product::withoutGlobalScopes()->forceCreate(array_merge([
            'tenant_id' => $tenant->id,
            'category_id' => $categoryId,
            'product_name' => $name,
            'product_code' => $sku,
            'product_price' => $price,
            'standard_cost' => $cost,
            'status' => 'active',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $productAttributes));

        if ($order !== null) {
            $qty = $itemAttributes['qty'] ?? 1;
            $unitPrice = (int) $price;
            $subtotal = $unitPrice * $qty;

            OrderItem::withoutGlobalScopes()->forceCreate(array_merge([
                'tenant_id' => $tenant->id,
                'store_id' => $store->id,
                'order_id' => $order->id,
                'product_id' => $product->id,
                'product_name_snapshot' => $product->product_name,
                'sku_snapshot' => $product->product_code,
                'qty' => $qty,
                'order_price' => $unitPrice,
                'order_subtotal' => $subtotal,
                'hpp_snapshot' => $cost,
                'net_line_total' => $subtotal,
                'created_at' => now(),
                'updated_at' => now(),
            ], $itemAttributes));
        }

        return $product;
    }
}
