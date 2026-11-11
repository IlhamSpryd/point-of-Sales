<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Models\Order;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreTarget;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Reports\Services\ExecutiveDashboardService;
use App\Modules\Reports\Services\ManagerDashboardService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DashboardRbacAndTenantIsolationTest extends TestCase
{
    use DatabaseTransactions;

    protected Tenant $tenantA;

    protected Tenant $tenantB;

    protected Store $storeA1;

    protected Store $storeA2;

    protected Store $storeB1;

    protected User $ownerA;

    protected User $managerA;

    protected User $cashierA;

    protected User $ownerB;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        app(\App\Services\Context\TenantContext::class)->clear();
        app(\App\Services\Context\TenantContext::class)->runWithoutTenant(function () {
            // Setup Tenant A & B
            $this->tenantA = $this->defaultTenant;
            $this->tenantB = Tenant::create([
                'name' => 'Competitor Tenant B',
                'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
                'access_status' => Tenant::ACCESS_ACTIVE,
                'status' => 'active',
            ]);

            // Stores
            $this->storeA1 = $this->defaultStore;
            $this->storeA2 = Store::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Branch South Jakarta',
                'address' => 'South Jakarta',
            ]);
            $this->storeB1 = Store::create([
                'tenant_id' => $this->tenantB->id,
                'name' => 'Branch B Surabaya',
                'address' => 'Surabaya',
            ]);

            // Roles with archetypes & permissions
            $executiveRole = Role::updateOrCreate(
                ['tenant_id' => $this->tenantA->id, 'name' => 'Owner'],
                [
                    'display_name' => 'Owner & Founder',
                    'dashboard_archetype' => 'executive',
                    'is_system_default' => true,
                    'is_admin' => true,
                    'permissions' => ['*'],
                ]
            );

            $managerRole = Role::updateOrCreate(
                ['tenant_id' => $this->tenantA->id, 'name' => 'Store Manager'],
                [
                    'display_name' => 'Store Manager',
                    'dashboard_archetype' => 'branch_ops',
                    'is_system_default' => true,
                    'is_admin' => false,
                    'permissions' => [
                        'dashboard',
                        'dashboard.metric.revenue',
                        'dashboard.action.approve_void_discount',
                    ],
                ]
            );

            $cashierRole = Role::updateOrCreate(
                ['tenant_id' => $this->tenantA->id, 'name' => 'Cashier'],
                [
                    'display_name' => 'Frontline Cashier',
                    'dashboard_archetype' => 'cashier_workspace',
                    'is_system_default' => true,
                    'is_admin' => false,
                    'permissions' => [
                        'dashboard',
                        'transaction.create',
                    ],
                ]
            );

            $ownerRoleB = Role::updateOrCreate(
                ['tenant_id' => $this->tenantB->id, 'name' => 'Owner B'],
                [
                    'display_name' => 'Owner B',
                    'dashboard_archetype' => 'executive',
                    'is_system_default' => true,
                    'is_admin' => true,
                    'permissions' => ['*'],
                ]
            );

            // Users
            $this->ownerA = User::factory()->create([
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA1->id,
                'role_id' => $executiveRole->id,
                'name' => 'Director Tenant A',
                'email' => 'director@tenant-a.com',
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
            $this->ownerA->stores()->syncWithoutDetaching([
                $this->storeA1->id => ['tenant_id' => $this->tenantA->id],
                $this->storeA2->id => ['tenant_id' => $this->tenantA->id],
            ]);

            $this->managerA = User::factory()->create([
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA1->id,
                'role_id' => $managerRole->id,
                'name' => 'Manager Branch A1',
                'email' => 'manager.a1@tenant-a.com',
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
            $this->managerA->stores()->syncWithoutDetaching([
                $this->storeA1->id => ['tenant_id' => $this->tenantA->id],
            ]);

            $this->cashierA = User::factory()->create([
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA1->id,
                'role_id' => $cashierRole->id,
                'name' => 'Cashier Branch A1',
                'email' => 'cashier.a1@tenant-a.com',
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
            $this->cashierA->stores()->syncWithoutDetaching([
                $this->storeA1->id => ['tenant_id' => $this->tenantA->id],
            ]);

            $this->ownerB = User::factory()->create([
                'tenant_id' => $this->tenantB->id,
                'store_id' => $this->storeB1->id,
                'role_id' => $ownerRoleB->id,
                'name' => 'Director Tenant B',
                'email' => 'director@tenant-b.com',
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
            $this->ownerB->stores()->syncWithoutDetaching([
                $this->storeB1->id => ['tenant_id' => $this->tenantB->id],
            ]);
        });
    }

    protected function createOrderRecord(array $attributes): int
    {
        return (int) \Illuminate\Support\Facades\DB::table('orders')->insertGetId(array_merge([
            'order_code' => 'ORD-'.uniqid(),
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            'order_date' => Carbon::today('Asia/Jakarta')->toDateString(),
            'subtotal_amount' => 100000.00,
            'discount_amount' => 0.00,
            'tax_amount' => 0.00,
            'service_charge_amount' => 0.00,
            'order_amount' => 100000.00,
            'order_status' => 'paid',
            'payment_status' => 'paid',
            'order_lifecycle_status' => 'completed',
            'fulfillment_status' => 'delivered',
            'currency_code' => 'IDR',
            'exchange_rate' => 1.0000,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    public function test_tenant_isolation_strictly_prevents_cross_tenant_data_leakage(): void
    {
        $today = Carbon::today('Asia/Jakarta')->toDateString();

        // Create Order in Tenant A (Rp 500.000)
        $this->createOrderRecord([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA1->id,
            'user_id' => $this->ownerA->id,
            'order_date' => $today,
            'subtotal_amount' => 500000.00,
            'discount_amount' => 0.00,
            'order_amount' => 500000.00,
            'order_status' => 'paid',
        ]);

        // Create Order in Tenant B (Rp 1.200.000)
        $this->createOrderRecord([
            'tenant_id' => $this->tenantB->id,
            'store_id' => $this->storeB1->id,
            'user_id' => $this->ownerB->id,
            'order_date' => $today,
            'subtotal_amount' => 1200000.00,
            'discount_amount' => 0.00,
            'order_amount' => 1200000.00,
            'order_status' => 'paid',
        ]);

        // Query Tenant A as Owner A
        $this->actingAs($this->ownerA);
        app(\App\Services\Context\TenantContext::class)->setTenantId($this->tenantA->id);
        $executiveService = app(ExecutiveDashboardService::class);
        $metricsA = $executiveService->getMetrics($this->ownerA, 'today');

        // Net sales in Tenant A must be exactly Rp 500.000 (never includes Tenant B's 1.200.000)
        $this->assertSame('500000.00', $metricsA['accrualSales']['net_sales']);

        // Query Tenant B as Owner B
        $this->actingAs($this->ownerB);
        app(\App\Services\Context\TenantContext::class)->setTenantId($this->tenantB->id);
        $metricsB = $executiveService->getMetrics($this->ownerB, 'today');

        // Net sales in Tenant B must be exactly Rp 1.200.000 (never includes Tenant A's 500.000)
        $this->assertSame('1200000.00', $metricsB['accrualSales']['net_sales']);
    }

    public function test_store_isolation_locks_branch_manager_to_assigned_store(): void
    {
        $today = Carbon::today('Asia/Jakarta')->toDateString();

        // Order in Store A1: Rp 350.000
        $this->createOrderRecord([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA1->id,
            'user_id' => $this->managerA->id,
            'order_date' => $today,
            'subtotal_amount' => 350000.00,
            'discount_amount' => 0.00,
            'order_amount' => 350000.00,
            'order_status' => 'paid',
        ]);

        // Order in Store A2: Rp 650.000
        $this->createOrderRecord([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA2->id,
            'user_id' => $this->ownerA->id,
            'order_date' => $today,
            'subtotal_amount' => 650000.00,
            'discount_amount' => 0.00,
            'order_amount' => 650000.00,
            'order_status' => 'paid',
        ]);

        $this->actingAs($this->managerA);
        app(\App\Services\Context\TenantContext::class)->setTenantId($this->tenantA->id);
        $managerService = app(ManagerDashboardService::class);

        // Even if Store Manager attempts to request Store A2, service enforces user's assigned store A1
        $managerMetrics = $managerService->getMetrics($this->managerA, $this->storeA2->id);

        $this->assertSame($this->storeA1->id, $managerMetrics['store_id']);
        $this->assertSame(350000.0, $managerMetrics['todayStoreSales']);
        $this->assertSame(1, $managerMetrics['todayTransactionCount']);
    }

    public function test_dynamic_rbac_widget_permission_gating_masks_profit_margin_payload(): void
    {
        $today = Carbon::today('Asia/Jakarta')->toDateString();

        $this->createOrderRecord([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA1->id,
            'user_id' => $this->ownerA->id,
            'order_date' => $today,
            'subtotal_amount' => 1000000.00,
            'discount_amount' => 0.00,
            'order_amount' => 1000000.00,
            'order_status' => 'paid',
        ]);

        $executiveService = app(ExecutiveDashboardService::class);

        // 1. User WITHOUT dashboard.metric.profit_margin (Manager A)
        $this->actingAs($this->managerA);
        app(\App\Services\Context\TenantContext::class)->setTenantId($this->tenantA->id);
        $metricsWithoutMargin = $executiveService->getMetrics($this->managerA, 'today');

        $this->assertFalse($metricsWithoutMargin['has_profit_margin_permission']);
        $this->assertFalse($metricsWithoutMargin['chartOptions']['is_dual_axis']);
        $this->assertArrayNotHasKey('cogs', $metricsWithoutMargin['accrualSales']);
        $this->assertArrayNotHasKey('gross_profit', $metricsWithoutMargin['accrualSales']);
        $this->assertArrayNotHasKey('gross_margin_pct', $metricsWithoutMargin['accrualSales']);

        // 2. User WITH dashboard.metric.profit_margin (Owner A)
        $this->actingAs($this->ownerA);
        app(\App\Services\Context\TenantContext::class)->setTenantId($this->tenantA->id);
        $metricsWithMargin = $executiveService->getMetrics($this->ownerA, 'today');

        $this->assertTrue($metricsWithMargin['has_profit_margin_permission']);
        $this->assertTrue($metricsWithMargin['chartOptions']['is_dual_axis']);
        $this->assertArrayHasKey('gross_profit', $metricsWithMargin['accrualSales']);
        $this->assertArrayHasKey('gross_margin_pct', $metricsWithMargin['accrualSales']);
    }

    public function test_archetype_resolution_routes_personas_to_correct_workspaces(): void
    {
        // 1. Cashier Role resolves to cashier_workspace and renders Fast POS CTA
        $this->actingAs($this->cashierA);
        app(\App\Services\Context\TenantContext::class)->setTenantId($this->tenantA->id);
        $this->assertSame('cashier_workspace', $this->cashierA->getDashboardArchetype());

        $responseCashier = $this->get(route('dashboard'));
        $responseCashier->assertStatus(200);
        $responseCashier->assertSee('Cashier Workspace');
        $responseCashier->assertSee('Open POS Register');
        $responseCashier->assertSee('Shift Payment Collection');

        // 2. Store Manager Role resolves to branch_ops and renders Approval Queue
        $this->createOrderRecord([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA1->id,
            'user_id' => $this->cashierA->id,
            'order_date' => Carbon::today('Asia/Jakarta')->toDateString(),
            'order_status' => 'void',
            'payment_status' => 'refunded',
            'order_lifecycle_status' => 'cancelled',
            'order_amount' => 75000.00,
            'subtotal_amount' => 75000.00,
            'void_reason' => 'Customer input wrong items',
            'created_at' => Carbon::now('Asia/Jakarta'),
        ]);

        $this->actingAs($this->managerA);
        app(\App\Services\Context\TenantContext::class)->setTenantId($this->tenantA->id);
        $this->assertSame('branch_ops', $this->managerA->getDashboardArchetype());

        $responseManager = $this->get(route('dashboard'));
        $responseManager->assertStatus(200);
        $responseManager->assertSee('Branch Ops');
        $responseManager->assertSee('Operational Authorization Queue');
        $responseManager->assertSee('Review &amp; Authorize', false);

        // 3. Owner Role resolves to executive and renders Executive BI & ECharts
        $this->actingAs($this->ownerA);
        app(\App\Services\Context\TenantContext::class)->setTenantId($this->tenantA->id);
        $this->assertSame('executive', $this->ownerA->getDashboardArchetype());

        $responseOwner = $this->get(route('dashboard'));
        $responseOwner->assertStatus(200);
        $responseOwner->assertSee('Executive BI');
        $responseOwner->assertSee('Sales &amp; Profit Margin Evolution', false);
        $responseOwner->assertSee('Multi-Branch Performance Ranking');
    }

    public function test_plan_vs_actual_attainment_calculation_in_executive_service(): void
    {
        $now = Carbon::now('Asia/Jakarta');
        $targetPeriod = $now->format('Y-m');

        // Create target: 100.000.000 for Store A1 this month
        StoreTarget::updateOrCreate(
            [
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA1->id,
                'target_period' => $targetPeriod,
            ],
            [
                'company_id' => null,
                'target_net_sales' => 100000000.00,
                'target_gross_profit' => 40000000.00,
                'target_transaction_count' => 1000,
            ]
        );

        // Create sales in Store A1: 25.000.000
        $this->createOrderRecord([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA1->id,
            'user_id' => $this->ownerA->id,
            'order_date' => $now->toDateString(),
            'subtotal_amount' => 25000000.00,
            'discount_amount' => 0.00,
            'order_amount' => 25000000.00,
            'order_status' => 'paid',
        ]);

        $this->actingAs($this->ownerA);
        app(\App\Services\Context\TenantContext::class)->setTenantId($this->tenantA->id);
        $executiveService = app(ExecutiveDashboardService::class);
        $metrics = $executiveService->getMetrics($this->ownerA, '30d', $this->storeA1->id);

        $this->assertArrayHasKey('planVsActual', $metrics);
        $this->assertSame('100000000.00', $metrics['planVsActual']['target_net_sales']);
        $this->assertSame('25.00', $metrics['planVsActual']['attainment_pct']);
        $this->assertNotEmpty($metrics['planVsActual']['run_rate_projection']);
    }
}
