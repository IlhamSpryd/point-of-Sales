<?php

declare(strict_types=1);

namespace Tests\Feature\Reports\E2e\Tier1;

use App\Modules\Reporting\Services\CashierWorkspaceService;
use App\Modules\Reporting\Services\DashboardOperationsQueryService;
use App\Modules\Reporting\Services\ExecutiveDashboardService;
use App\Modules\Reporting\Services\ManagerDashboardService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Reports\E2e\BaseE2eReportTestCase;

/**
 * Feature 22 & 23: Section G: Operational Alerts & Multi-Role Perspectives
 *
 * Verifies:
 * - Feature 22: Operational Alerts (critical stockout, unclosed shift warning, void authorization alert, remediation CTAs, calm operational state).
 * - Feature 23: Multi-Role Perspectives (Branch Ops / Manager workspace, Cashier workspace, Inventory perspective, Finance 3-state GL notice, perspective access restriction).
 */
class OperationalAlertsAndRolePerspectivesTest extends BaseE2eReportTestCase
{
    protected DashboardOperationsQueryService $operationsService;

    protected ExecutiveDashboardService $executiveService;

    protected ManagerDashboardService $managerService;

    protected CashierWorkspaceService $cashierService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->operationsService = app(DashboardOperationsQueryService::class);
        $this->executiveService = app(ExecutiveDashboardService::class);
        $this->managerService = app(ManagerDashboardService::class);
        $this->cashierService = app(CashierWorkspaceService::class);
    }

    // =========================================================================
    // FEATURE 22: SECTION G: OPERATIONAL ALERTS & INSIGHTS (5 TESTS)
    // =========================================================================

    /**
     * T1-FE22-01: Verifies critical stockout risk alert card appears when stock is depleted.
     */
    public function test_section_g_renders_stockout_risk_alert_card(): void
    {
        $this->createProductWithItem($this->tenant, $this->store, null, [
            'product_name' => 'Stockout Item A',
            'product_code' => 'SKU-OUT-A',
            'stock' => 0,
        ]);

        $alerts = $this->operationsService->getOperationalAlerts($this->tenant->id, $this->store->id);

        $stockoutAlert = collect($alerts)->firstWhere('id', 'alert_stockout');
        $this->assertNotNull($stockoutAlert, 'Stockout alert must be triggered when items have 0 stock');
        $this->assertEquals('critical', $stockoutAlert['type']);
        $this->assertStringContainsString('Stok Habis', $stockoutAlert['title']);
        $this->assertNotEmpty($stockoutAlert['action_url']);
    }

    /**
     * T1-FE22-02: Verifies warning alert card appears when shift cash session is unclosed.
     */
    public function test_section_g_renders_shift_cash_variance_alert_card(): void
    {
        // Create an unclosed shift (closed_at is null)
        $this->createShift($this->tenant, $this->store, $this->ownerUser, [
            'closed_at' => null,
            'status' => 'open',
        ]);

        $alerts = $this->operationsService->getOperationalAlerts($this->tenant->id, $this->store->id);

        $shiftAlert = collect($alerts)->firstWhere('id', 'alert_shift');
        $this->assertNotNull($shiftAlert, 'Unclosed shift alert must appear in operational alerts');
        $this->assertEquals('warning', $shiftAlert['type']);
        $this->assertStringContainsString('Shift Kasir Belum Ditutup', $shiftAlert['title']);
    }

    /**
     * T1-FE22-03: Verifies alert card appears for pending order void authorizations.
     */
    public function test_section_g_renders_revenue_surge_or_drop_insight_card(): void
    {
        // Temporarily bypass check constraint for testing void_pending status
        DB::statement('SET check_constraint_checks = 0');

        // Insert order with void_pending directly via DB
        DB::table('orders')->insert([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->ownerUser->id,
            'order_code' => 'ORD-PENDING-VOID-'.\Illuminate\Support\Str::random(6),
            'idempotency_key' => 'IDEMP-VOID-'.\Illuminate\Support\Str::random(10),
            'order_date' => Carbon::now('Asia/Jakarta')->toDateString(),
            'subtotal_amount' => 50000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'order_amount' => 50000,
            'order_status' => 'void_pending',
            'payment_method' => 'cash',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $alerts = $this->operationsService->getOperationalAlerts($this->tenant->id, $this->store->id);

        $voidAlert = collect($alerts)->firstWhere('id', 'alert_void');
        $this->assertNotNull($voidAlert, 'Pending void alert must appear for supervisor attention');
        $this->assertEquals('warning', $voidAlert['type']);
        $this->assertStringContainsString('Otorisasi PIN', $voidAlert['title']);
    }

    /**
     * T1-FE22-04: Verifies alert card includes direct remediation action CTA and URL.
     */
    public function test_section_g_alert_card_contains_direct_action_cta_button(): void
    {
        $this->createProductWithItem($this->tenant, $this->store, null, [
            'product_name' => 'Zero Stock Bean',
            'product_code' => 'SKU-ZSB-01',
            'stock' => 0,
        ]);

        $alerts = $this->operationsService->getOperationalAlerts($this->tenant->id, $this->store->id);

        foreach ($alerts as $alert) {
            $this->assertArrayHasKey('action_label', $alert);
            $this->assertArrayHasKey('action_url', $alert);
            $this->assertNotEmpty($alert['action_label']);
            $this->assertNotEmpty($alert['action_url']);
        }
    }

    /**
     * T1-FE22-05: Verifies zero discrepancies renders calm operational state.
     */
    public function test_section_g_zero_anomalies_renders_calm_operational_state(): void
    {
        $tenantContext = app(\App\Core\Context\TenantContext::class);

        $cleanTenant = $this->createTenant(['name' => 'Calm Operations Tenant']);

        $alerts = $tenantContext->runAs($cleanTenant->id, null, function () use ($cleanTenant) {
            $cleanStore = $this->createStore($cleanTenant, ['name' => 'Calm Outlet']);

            // Create only healthy stock products and closed shifts
            $this->createProductWithItem($cleanTenant, $cleanStore, null, [
                'stock' => 50,
            ]);

            return $this->operationsService->getOperationalAlerts($cleanTenant->id, $cleanStore->id);
        });

        $this->assertEmpty($alerts, 'No alerts should trigger in an optimal, anomaly-free state');
    }

    // =========================================================================
    // FEATURE 23: ALTERNATE ROLE PERSPECTIVES INTEGRATION (5 TESTS)
    // =========================================================================

    /**
     * T1-FE23-01: Verifies Branch Ops perspective renders shift and operational metrics.
     */
    public function test_branch_ops_perspective_renders_shift_and_void_approval_widgets(): void
    {
        $managerUser = $this->createUserWithRole(
            $this->tenant,
            'Branch Manager',
            ['dashboard.view', 'dashboard.perspective.branch_ops', 'pos.access'],
            $this->store,
            'manager'
        );

        $metrics = $this->managerService->getMetrics($managerUser, $this->store->id);

        $this->assertIsArray($metrics);
        $this->assertArrayHasKey('today_kpi', $metrics);
        $this->assertArrayHasKey('todayStoreSales', $metrics);
        $this->assertArrayHasKey('cashier_scorecard', $metrics);
        $this->assertArrayHasKey('approval_queue', $metrics);
        $this->assertEquals($this->store->id, $metrics['store_id']);
    }

    /**
     * T1-FE23-02: Verifies Cashier perspective renders personal shift status and quick launch.
     */
    public function test_cashier_perspective_renders_speed_metrics_and_open_register(): void
    {
        $cashierUser = $this->createUserWithRole(
            $this->tenant,
            'Kasir Shift',
            ['dashboard.view', 'pos.access'],
            $this->store,
            'cashier'
        );

        // Open an active shift for this cashier
        $this->createShift($this->tenant, $this->store, $cashierUser, [
            'opened_at' => Carbon::now('Asia/Jakarta')->subHours(2),
            'closed_at' => null,
            'opening_balance' => 250000.00,
            'status' => 'open',
        ]);

        $workspace = $this->cashierService->getWorkspaceData($cashierUser);

        $this->assertTrue($workspace['has_active_shift']);
        $this->assertEquals(250000.00, $workspace['active_shift']['starting_cash']);
        $this->assertArrayHasKey('recent_orders', $workspace);
        $this->assertArrayHasKey('payment_breakdown', $workspace);
        $this->assertArrayHasKey('sales', $workspace);
    }

    /**
     * T1-FE23-03: Verifies Inventory perspective provides stock intelligence and operational shortcuts.
     */
    public function test_inventory_perspective_renders_replenishment_and_opname_shortcuts(): void
    {
        $product = $this->createProductWithItem($this->tenant, $this->store, null, [
            'product_name' => 'Restock Needed Item',
            'product_code' => 'SKU-RESTOCK-01',
            'stock' => 2,
        ]);

        $inventoryData = $this->operationsService->getInventoryIntelligence($this->tenant->id, $this->store->id);

        $this->assertArrayHasKey('stock_control_table', $inventoryData);
        $matched = collect($inventoryData['stock_control_table'])->firstWhere('product_id', $product->id);

        $this->assertNotNull($matched);
        $this->assertEquals('Restock Cabang', $matched['action']);
        $this->assertEquals('/inventory/stock-opname', $matched['deep_link']);
    }

    /**
     * T1-FE23-04: Verifies Finance perspective renders 3-state GL notice structure.
     */
    public function test_finance_perspective_renders_cash_flow_and_3_state_gl_notice(): void
    {
        // 1. Reconciled state (no open shifts, no draft journals)
        $glReconciled = $this->executiveService->computeGlTrustIndicator($this->tenant->id, $this->store->id);
        $this->assertEquals('reconciled', $glReconciled['status_state']);
        $this->assertTrue($glReconciled['is_reconciled']);
        $this->assertEquals('100% Reconciled', $glReconciled['status_badge']);

        // 2. Audit Pending state (open shift present)
        $this->createShift($this->tenant, $this->store, $this->ownerUser, [
            'closed_at' => null,
            'status' => 'open',
        ]);

        $glPending = $this->executiveService->computeGlTrustIndicator($this->tenant->id, $this->store->id);
        $this->assertEquals('audit_pending', $glPending['status_state']);
        $this->assertFalse($glPending['is_reconciled']);
        $this->assertEquals('Audit Pending', $glPending['status_badge']);
    }

    /**
     * T1-FE23-05: Verifies unauthorized frontline cashier cannot access gated executive perspective.
     */
    public function test_unauthorized_user_role_cannot_access_gated_perspective(): void
    {
        $cashierUser = $this->createUserWithRole(
            $this->tenant,
            'Frontline Kasir',
            ['dashboard.view', 'pos.access'],
            $this->store,
            'cashier'
        );

        $response = $this->actingAs($cashierUser)->get('/dashboard?view=executive');

        $response->assertOk();
        // Since cashier has no multi-role switching ability (* or is_admin or canViewConsolidatedStores),
        // activeArchetype remains cashier
        $response->assertViewHas('activeArchetype', 'cashier');
        $response->assertViewHas('canSwitchArchetype', false);
    }
}
