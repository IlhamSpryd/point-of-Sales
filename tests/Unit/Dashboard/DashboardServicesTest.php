<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use App\Core\Context\TenantContext;
use App\Modules\Organization\Models\Role;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Models\Tenant;
use App\Modules\Organization\Models\User;
use App\Modules\Reporting\Models\StoreTarget;
use App\Modules\Reporting\Services\DashboardOperationsQueryService;
use App\Modules\Reporting\Services\DashboardOverviewQueryService;
use App\Modules\Reporting\Services\ExecutiveDashboardService;
use App\Platform\Authorization\AbilityRegistry;
use App\Platform\Authorization\Scope;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardServicesTest extends TestCase
{
    use DatabaseTransactions;

    protected Tenant $tenant;

    protected Store $store1;

    protected Store $store2;

    protected User $owner;

    protected User $manager;

    protected Role $ownerRole;

    protected Role $managerRole;

    protected DashboardOverviewQueryService $overviewService;

    protected DashboardOperationsQueryService $operationsService;

    protected ExecutiveDashboardService $executiveService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $tenantContext = app(TenantContext::class);
        $tenantContext->clear();

        $tenantContext->runWithoutTenant(function () {
            $this->tenant = Tenant::withoutGlobalScopes()->forceCreate([
                'name' => 'Unit Test Tenant',
                'slug' => 'unit-test-'.uniqid(),
                'status' => 'active',
                'onboarding_status' => 'completed',
                'access_status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $r = rand(1000, 9999);
            $this->store1 = Store::withoutGlobalScopes()->forceCreate([
                'tenant_id' => $this->tenant->id,
                'name' => 'Store Pusat Test',
                'code' => "STR-P-{$r}",
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->store2 = Store::withoutGlobalScopes()->forceCreate([
                'tenant_id' => $this->tenant->id,
                'name' => 'Store Selatan Test',
                'code' => "STR-S-{$r}",
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->ownerRole = Role::withoutGlobalScopes()->forceCreate([
                'name' => 'Owner Custom',
                'role_code' => "ROL-O-{$r}",
                'tenant_id' => $this->tenant->id,
                'permissions' => ['*'],
                'dashboard_archetype' => 'executive',
                'is_system_default' => false,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->managerRole = Role::withoutGlobalScopes()->forceCreate([
                'name' => 'Branch Manager Custom',
                'role_code' => "ROL-M-{$r}",
                'tenant_id' => $this->tenant->id,
                'permissions' => ['dashboard.view', 'dashboard.metric.revenue'],
                'dashboard_archetype' => 'branch_manager',
                'is_system_default' => false,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $ownerEmail = 'owner_dash_'.uniqid().'@apeiron.id';
            $this->owner = User::withoutGlobalScopes()->forceCreate([
                'name' => 'Budi Owner',
                'email' => $ownerEmail,
                'email_normalized' => $ownerEmail,
                'password' => bcrypt('password'),
                'tenant_id' => $this->tenant->id,
                'store_id' => $this->store1->id,
                'role_id' => $this->ownerRole->id,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $mgrEmail = 'mgr_dash_'.uniqid().'@apeiron.id';
            $this->manager = User::withoutGlobalScopes()->forceCreate([
                'name' => 'Siti Manager',
                'email' => $mgrEmail,
                'email_normalized' => $mgrEmail,
                'password' => bcrypt('password'),
                'tenant_id' => $this->tenant->id,
                'store_id' => $this->store2->id,
                'role_id' => $this->managerRole->id,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->owner->stores()->attach($this->store1->id, ['tenant_id' => $this->tenant->id]);
            $this->manager->stores()->attach($this->store2->id, ['tenant_id' => $this->tenant->id]);
        });

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->overviewService = app(DashboardOverviewQueryService::class);
        $this->operationsService = app(DashboardOperationsQueryService::class);
        $this->executiveService = app(ExecutiveDashboardService::class);
    }

    public function test_ability_registry_contains_all_wave1_dashboard_abilities(): void
    {
        $registry = app(AbilityRegistry::class);

        $expected = [
            'dashboard.view' => Scope::BRANCH,
            'dashboard.scope.all_stores' => Scope::TENANT,
            'dashboard.metric.revenue' => Scope::BRANCH,
            'dashboard.metric.profit_margin' => Scope::TENANT,
            'dashboard.metric.finance_gl' => Scope::COMPANY,
            'dashboard.action.approve_void_discount' => Scope::BRANCH,
            'dashboard.perspective.branch_ops' => Scope::BRANCH,
            'dashboard.perspective.cashier' => Scope::BRANCH,
            'dashboard.perspective.inventory' => Scope::BRANCH,
            'dashboard.perspective.finance' => Scope::COMPANY,
        ];

        foreach ($expected as $abilityName => $expectedScope) {
            $this->assertTrue($registry->has($abilityName), "Ability {$abilityName} harus terdaftar di AbilityRegistry.");
            $ability = $registry->get($abilityName);
            $this->assertNotNull($ability);
            $this->assertSame($expectedScope, $ability->scope);
            $this->assertSame('dashboard', $ability->module);
        }

        $this->assertTrue($registry->get('dashboard.action.approve_void_discount')->requiresApproval);
        $this->assertFalse($registry->get('dashboard.view')->requiresApproval);
    }

    public function test_dashboard_overview_query_service_honest_empty_state(): void
    {
        $kpis = $this->overviewService->getKpis($this->tenant->id, $this->store1->id, '30d', true);

        $this->assertSame(0.0, $kpis['gross_revenue']);
        $this->assertSame(0.0, $kpis['net_revenue']);
        $this->assertSame('0.00', $kpis['net_sales']);
        $this->assertSame('Rp 0', $kpis['net_sales_formatted']);
        $this->assertSame(0, $kpis['transactions']);
        $this->assertSame(0, $kpis['total_orders']);
        $this->assertSame('0.00', $kpis['aov']);
        $this->assertSame('Rp 0', $kpis['aov_formatted']);
        $this->assertSame('0.00', $kpis['cash_collected']);
        $this->assertSame('0.00', $kpis['cogs']);
        $this->assertSame('0.00', $kpis['gross_profit']);
        $this->assertSame('0.00', $kpis['gross_margin_pct']);
        $this->assertCount(7, $kpis['sparklines']['net_revenue']);
        $this->assertSame(0.0, $kpis['sparklines']['net_revenue'][0]);
    }

    public function test_dashboard_overview_query_service_sales_and_permission_gating(): void
    {
        $today = Carbon::today('Asia/Jakarta')->toDateString();

        $catId = DB::table('categories')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Minuman',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $prodId = DB::table('products')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'category_id' => $catId,
            'product_name' => 'Kopi Susu Gula Aren',
            'product_code' => 'KOP-01',
            'product_price' => 20000,
            'standard_cost' => 8000,
            'stock' => 100,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orderId = DB::table('orders')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store1->id,
            'user_id' => $this->owner->id,
            'order_code' => 'ORD-TEST-001',
            'idempotency_key' => (string) Str::uuid(),
            'order_date' => $today,
            'order_status' => 'paid',
            'payment_method' => 'qris',
            'subtotal_amount' => 100000.00,
            'discount_amount' => 10000.00,
            'order_amount' => 90000.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('order_items')->insert([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store1->id,
            'order_id' => $orderId,
            'product_id' => $prodId,
            'qty' => 5,
            'order_price' => 20000.00,
            'order_subtotal' => 100000.00,
            'item_discount_amount' => 10000.00,
            'net_line_total' => 90000.00,
            'hpp_snapshot' => 8000.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('payments')->insert([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store1->id,
            'order_id' => $orderId,
            'payment_method' => 'qris',
            'amount' => 90000.00,
            'status' => 'captured',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 1. User dengan izin profit_margin
        $kpisWithMargin = $this->overviewService->getKpis($this->tenant->id, $this->store1->id, 'today', true);
        $this->assertEquals(90000.00, $kpisWithMargin['net_revenue']);
        $this->assertSame(1, $kpisWithMargin['transactions']);
        $this->assertArrayHasKey('cogs', $kpisWithMargin);
        $this->assertArrayHasKey('gross_profit', $kpisWithMargin);
        $this->assertArrayHasKey('gross_margin_pct', $kpisWithMargin);
        $this->assertEquals('40000.00', $kpisWithMargin['cogs']);
        $this->assertEquals('50000.00', $kpisWithMargin['gross_profit']);

        // 2. User tanpa izin profit_margin
        $kpisWithoutMargin = $this->overviewService->getKpis($this->tenant->id, $this->store1->id, 'today', false);
        $this->assertEquals(90000.00, $kpisWithoutMargin['net_revenue']);
        $this->assertArrayNotHasKey('cogs', $kpisWithoutMargin);
        $this->assertArrayNotHasKey('gross_profit', $kpisWithoutMargin);
        $this->assertArrayNotHasKey('gross_margin_pct', $kpisWithoutMargin);
    }

    public function test_dashboard_overview_query_service_revenue_series_and_combo(): void
    {
        $series = $this->overviewService->getRevenueSeries($this->tenant->id, $this->store1->id, '7d', 'daily', true);
        $this->assertNotEmpty($series['labels']);
        $this->assertNotEmpty($series['gross_series']);
        $this->assertNotEmpty($series['net_series']);
        $this->assertNotEmpty($series['prior_series']);
        $this->assertNotEmpty($series['target_series']);
        $this->assertNotEmpty($series['margin_series']);

        $seriesNoMargin = $this->overviewService->getRevenueSeries($this->tenant->id, $this->store1->id, '7d', 'daily', false);
        $this->assertEmpty($seriesNoMargin['margin_series']);

        $combo = $this->overviewService->getProfitabilityCombo($this->tenant->id, $this->store1->id, '7d', true);
        $this->assertNotEmpty($combo['labels']);
        $this->assertNotEmpty($combo['gross_profit_series']);
        $this->assertNotEmpty($combo['margin_pct_series']);

        $comboNoMargin = $this->overviewService->getProfitabilityCombo($this->tenant->id, $this->store1->id, '7d', false);
        $this->assertEmpty($comboNoMargin['labels']);
    }

    public function test_dashboard_overview_query_service_waterfall_and_sales_mix(): void
    {
        $waterfall = $this->overviewService->getRevenueWaterfallBridge($this->tenant->id, $this->store1->id, '30d', true);
        $this->assertNotEmpty($waterfall['steps']);
        $stepNames = array_column($waterfall['steps'], 'step');
        $this->assertContains('gross_sales', $stepNames);
        $this->assertContains('discounts', $stepNames);
        $this->assertContains('voids_returns', $stepNames);
        $this->assertContains('net_sales', $stepNames);
        $this->assertContains('cogs', $stepNames);
        $this->assertContains('gross_profit', $stepNames);

        $mix = $this->overviewService->getSalesMix($this->tenant->id, $this->store1->id, '30d');
        $this->assertArrayHasKey('by_category', $mix);
        $this->assertArrayHasKey('by_payment_method', $mix);
    }

    public function test_dashboard_operations_query_service_demand_heatmap_matrix(): void
    {
        $heatmap = $this->operationsService->getDemandHeatmap($this->tenant->id, $this->store1->id, '30d');

        $this->assertCount(105, $heatmap['cells'], 'Heatmap matrix wajib berisi 105 sel (7 hari x 15 jam operational).');
        $this->assertArrayHasKey('day_index', $heatmap['cells'][0]);
        $this->assertArrayHasKey('hour', $heatmap['cells'][0]);
        $this->assertArrayHasKey('intensity', $heatmap['cells'][0]);
        $this->assertArrayHasKey('peak_hour', $heatmap);
        $this->assertArrayHasKey('quietest_hour', $heatmap);
        $this->assertCount(4, $heatmap['dayparts'], 'Wajib memiliki 4 segmen daypart.');
    }

    public function test_dashboard_operations_query_service_outlet_comparison(): void
    {
        StoreTarget::withoutGlobalScopes()->forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store1->id,
            'target_period' => Carbon::now('Asia/Jakarta')->format('Y-m'),
            'target_net_sales' => '50000000.00',
            'target_gross_margin_pct' => '40.00',
            'max_shrinkage_pct' => '1.00',
            'max_labor_cost_pct' => '15.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $comparison = $this->operationsService->getOutletComparison($this->tenant->id, '30d', true);
        $this->assertCount(2, $comparison);
        $storeIds = array_column($comparison, 'store_id');
        $this->assertContains($this->store1->id, $storeIds);
        $this->assertContains($this->store2->id, $storeIds);

        $first = $comparison[0];
        $this->assertArrayHasKey('net_sales', $first);
        $this->assertArrayHasKey('target_net_sales', $first);
        $this->assertArrayHasKey('attainment_pct', $first);
        $this->assertArrayHasKey('sparkline', $first);
        $this->assertArrayHasKey('gross_profit', $first);
    }

    public function test_dashboard_operations_query_service_product_unit_economics_and_bom(): void
    {
        $catId = DB::table('categories')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Makanan',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $prodId = DB::table('products')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'category_id' => $catId,
            'product_name' => 'Burger Wagyu Deluxe',
            'product_code' => 'BGR-WAGYU',
            'product_price' => 75000,
            'standard_cost' => 35000,
            'stock' => 50,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ingId = DB::table('ingredients')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'name' => 'Daging Wagyu Patty',
            'ingredient_code' => 'ING-WAGYU',
            'unit' => 'gr',
            'cost_per_unit' => 250,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('product_ingredients')->insert([
            'tenant_id' => $this->tenant->id,
            'product_id' => $prodId,
            'ingredient_id' => $ingId,
            'quantity_required' => 120.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $economics = $this->operationsService->getProductUnitEconomics($this->tenant->id, $this->store1->id, '30d');
        $this->assertArrayHasKey('items', $economics);
        $this->assertArrayHasKey('total', $economics);

        $bom = $this->operationsService->getProductRecipeBom($this->tenant->id, $prodId);
        $this->assertNotNull($bom['product']);
        $this->assertSame('Burger Wagyu Deluxe', $bom['product']['name']);
        $this->assertNotEmpty($bom['ingredients']);
        $this->assertSame('Daging Wagyu Patty', $bom['ingredients'][0]['name']);
        $this->assertEquals(30000.0, $bom['ingredients'][0]['total_cost']);

        $nonExistentBom = $this->operationsService->getProductRecipeBom($this->tenant->id, 999999);
        $this->assertNull($nonExistentBom['product']);
        $this->assertEmpty($nonExistentBom['ingredients']);
    }

    public function test_dashboard_operations_query_service_inventory_intelligence_and_transactions(): void
    {
        $inventory = $this->operationsService->getInventoryIntelligence($this->tenant->id, $this->store1->id);
        $this->assertArrayHasKey('summary', $inventory);
        $this->assertArrayHasKey('total_skus', $inventory['summary']);
        $this->assertArrayHasKey('low_stock_count', $inventory['summary']);
        $this->assertArrayHasKey('out_of_stock_count', $inventory['summary']);
        $this->assertArrayHasKey('valuation', $inventory['summary']);
        $this->assertArrayHasKey('stock_control_table', $inventory);

        $tx = $this->operationsService->getRecentTransactions($this->tenant->id, $this->store1->id);
        $this->assertArrayHasKey('items', $tx);
        $this->assertArrayHasKey('total', $tx);

        $alerts = $this->operationsService->getOperationalAlerts($this->tenant->id, $this->store1->id);
        $this->assertIsArray($alerts);
    }

    public function test_executive_dashboard_service_coordinator_backward_compatibility(): void
    {
        $this->actingAs($this->owner);
        $metrics = $this->executiveService->getMetrics($this->owner, 'today', null, null, true);

        $this->assertArrayHasKey('kpi', $metrics);
        $this->assertArrayHasKey('accrualSales', $metrics);
        $this->assertArrayHasKey('accrual_sales', $metrics);
        $this->assertArrayHasKey('planVsActual', $metrics);
        $this->assertArrayHasKey('glTrustIndicator', $metrics);
        $this->assertArrayHasKey('branchRanking', $metrics);
        $this->assertArrayHasKey('chartOptions', $metrics);
        $this->assertTrue($metrics['is_consolidated']);
        $this->assertTrue($metrics['has_profit_margin_permission']);

        // Manager tanpa izin konsolidasi dikunci ke tokonya sendiri
        $this->actingAs($this->manager);
        $mgrMetrics = $this->executiveService->getMetrics($this->manager, 'today', null, null, true);
        $this->assertFalse($mgrMetrics['is_consolidated']);
        $this->assertSame($this->store2->id, $mgrMetrics['store_id']);
        $this->assertFalse($mgrMetrics['has_profit_margin_permission']);
    }

    public function test_executive_dashboard_service_3_state_gl_reconciliation(): void
    {
        // 1. Shift masih terbuka => audit_pending
        DB::table('shifts')->insert([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store1->id,
            'user_id' => $this->owner->id,
            'opening_balance' => 100000,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $glIndicatorPending = $this->executiveService->computeGlTrustIndicator($this->tenant->id, $this->store1->id);
        $this->assertSame('audit_pending', $glIndicatorPending['status_state']);
        $this->assertSame('Audit Pending', $glIndicatorPending['status_badge']);
        $this->assertGreaterThan(0, $glIndicatorPending['unclosed_shifts']);
        $this->assertFalse($glIndicatorPending['is_reconciled']);

        // 2. Semua shift ditutup => reconciled
        DB::table('shifts')->where('tenant_id', $this->tenant->id)->update(['closed_at' => now()]);
        $glIndicatorReconciled = $this->executiveService->computeGlTrustIndicator($this->tenant->id, $this->store1->id);
        $this->assertSame('reconciled', $glIndicatorReconciled['status_state']);
        $this->assertSame('100% Reconciled', $glIndicatorReconciled['status_badge']);
        $this->assertSame(0, $glIndicatorReconciled['unclosed_shifts']);
        $this->assertTrue($glIndicatorReconciled['is_reconciled']);
    }

    public function test_executive_dashboard_service_cache_and_invalidation(): void
    {
        $this->actingAs($this->owner);
        $res1 = $this->executiveService->getMetrics($this->owner, 'today', $this->store1->id, null, false);
        $this->assertNotNull($res1);

        // Invalidate cache
        $this->executiveService->invalidateCache($this->tenant->id, $this->store1->id);

        // Dapat dipanggil ulang dengan bypassCache
        $res2 = $this->executiveService->getMetrics($this->owner, 'today', $this->store1->id, null, true);
        $this->assertNotNull($res2);
    }
}
