<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Store;
use App\Models\StoreTarget;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Reports\Services\ExecutiveDashboardService;
use App\Modules\Reports\Services\ManagerDashboardService;
use App\Modules\Users\Services\CashierPinService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DashboardOperationalWiringTest extends TestCase
{
    use DatabaseTransactions;

    protected Tenant $tenant;

    protected Store $store;

    protected User $ownerUser;

    protected User $supervisorUser;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $tenantContext = app(\App\Services\Context\TenantContext::class);
        $tenantContext->clear();

        $tenantContext->runWithoutTenant(function () {
            $this->tenant = $this->defaultTenant;
            $this->store = $this->defaultStore;

            $ownerRole = Role::firstOrCreate(
                ['name' => 'Owner', 'tenant_id' => $this->tenant->id],
                [
                    'role_code' => 'ROL-OWNER',
                    'permissions' => ['*'],
                    'dashboard_archetype' => 'executive',
                    'is_system_default' => true,
                    'is_active' => true,
                ]
            );

            $supervisorRole = Role::firstOrCreate(
                ['name' => 'Supervisor', 'tenant_id' => $this->tenant->id],
                [
                    'role_code' => 'ROL-SUPER',
                    'permissions' => [
                        'dashboard',
                        'dashboard.action.approve_void_discount',
                        'dashboard.metric.cash_variance',
                        'dashboard.metric.revenue',
                        'orders.*',
                        'shifts.*',
                    ],
                    'dashboard_archetype' => 'branch_ops',
                    'is_system_default' => true,
                    'is_active' => true,
                ]
            );

            $this->ownerUser = User::factory()->create([
                'tenant_id' => $this->tenant->id,
                'role_id' => $ownerRole->id,
                'email' => 'owner@operational.test',
                'email_verified_at' => now(),
                'is_active' => true,
            ]);

            $this->supervisorUser = User::factory()->create([
                'tenant_id' => $this->tenant->id,
                'role_id' => $supervisorRole->id,
                'email' => 'supervisor@operational.test',
                'email_verified_at' => now(),
                'is_active' => true,
            ]);

            $this->ownerUser->stores()->syncWithoutDetaching([$this->store->id => ['tenant_id' => $this->tenant->id]]);
            $this->supervisorUser->stores()->syncWithoutDetaching([$this->store->id => ['tenant_id' => $this->tenant->id]]);

            // Configure PIN for supervisor
            app(CashierPinService::class)->setPin($this->supervisorUser, '5555');
        });
    }

    public function test_owner_can_create_custom_role_with_dashboard_archetype_and_granular_permissions(): void
    {
        $response = $this->actingAs($this->ownerUser)
            ->post(route('roles.store'), [
                'name' => 'Audit Specialist',
                'description' => 'Dedicated branch ops and inventory auditor',
                'dashboard_archetype' => 'branch_ops',
                'permissions' => [
                    'dashboard',
                    'dashboard.metric.revenue',
                    'dashboard.metric.cash_variance',
                    'dashboard.action.approve_void_discount',
                    'dashboard.scope.all_stores',
                ],
                'is_active' => 1,
            ]);

        $response->assertRedirect(route('roles.index'));

        $this->assertDatabaseHas('roles', [
            'tenant_id' => $this->tenant->id,
            'name' => 'Audit Specialist',
            'dashboard_archetype' => 'branch_ops',
            'is_system_default' => 0,
        ]);

        $createdRole = Role::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('name', 'Audit Specialist')
            ->first();

        $this->assertNotNull($createdRole);
        $this->assertTrue($createdRole->hasPermission('dashboard.action.approve_void_discount'));
        $this->assertTrue($createdRole->hasPermission('dashboard.scope.all_stores'));
    }

    public function test_owner_can_duplicate_role_and_it_creates_custom_clone(): void
    {
        $systemSupervisor = Role::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('name', 'Supervisor')
            ->firstOrFail();

        $response = $this->actingAs($this->ownerUser)
            ->post(route('roles.duplicate', $systemSupervisor->id));

        $newRole = Role::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('name', 'like', 'Supervisor (Copy)%')
            ->first();

        $this->assertNotNull($newRole);
        $response->assertRedirect(route('roles.edit', $newRole->id));
        $this->assertFalse((bool) $newRole->is_system_default);
        $this->assertEquals($systemSupervisor->dashboard_archetype, $newRole->dashboard_archetype);
        $this->assertTrue($newRole->hasPermission('dashboard.action.approve_void_discount'));
    }

    public function test_system_default_role_is_protected_from_deletion(): void
    {
        $systemRole = Role::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('name', 'Supervisor')
            ->firstOrFail();

        $response = $this->actingAs($this->ownerUser)
            ->delete(route('roles.destroy', $systemRole->id));

        $response->assertRedirect(route('roles.index'));
        $response->assertSessionHas('error');

        $this->assertDatabaseHas('roles', [
            'id' => $systemRole->id,
            'name' => 'Supervisor',
            'deleted_at' => null,
        ]);
    }

    public function test_owner_can_save_store_targets_via_ui_endpoint_and_refreshes_plan_vs_actual(): void
    {
        $currentPeriod = Carbon::now('Asia/Jakarta')->format('Y-m');

        $response = $this->actingAs($this->ownerUser)
            ->postJson(route('dashboard.store-targets.save'), [
                'store_id' => $this->store->id,
                'target_period' => $currentPeriod,
                'target_net_sales' => 175000000,
                'target_gross_margin_pct' => 46.5,
                'max_shrinkage_pct' => 1.5,
                'max_labor_cost_pct' => 20.0,
            ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
        ]);

        $this->assertDatabaseHas('store_targets', [
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'target_period' => $currentPeriod,
            'target_net_sales' => 175000000.00,
        ]);

        // Verifikasi kalkulasi Plan vs Actual langsung ter-update di Executive Dashboard
        app(\App\Services\Context\TenantContext::class)->setTenantId($this->tenant->id);
        $execService = app(ExecutiveDashboardService::class);
        $data = $execService->getMetrics($this->ownerUser, '30d', $this->store->id, null, true);

        $this->assertNotNull($data['planVsActual']);
        $this->assertEquals(175000000, (float) $data['planVsActual']['target_revenue']);
    }

    public function test_supervisor_can_approve_void_order_with_valid_pin(): void
    {
        $order = Order::withoutGlobalScopes()->forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->supervisorUser->id,
            'order_code' => 'ORD-VOID-TEST-99',
            'idempotency_key' => 'IDEMP-VOID-99',
            'order_amount' => 85000,
            'subtotal_amount' => 85000,
            'order_date' => Carbon::now('Asia/Jakarta')->toDateString(),
            'order_status' => OrderStatus::Paid->value,
            'payment_method' => 'cash',
            'void_reason' => 'Mistake in cart item quantity',
            'voided_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Pastikan order awalnya masuk ke antrean approval queue
        app(\App\Services\Context\TenantContext::class)->setTenantId($this->tenant->id);
        $managerService = app(ManagerDashboardService::class);
        $mMetricsBefore = $managerService->getMetrics($this->supervisorUser, $this->store->id);
        $foundInQueue = collect($mMetricsBefore['approvalQueue'])->contains('ticket_no', 'ORD-VOID-TEST-99');
        $this->assertTrue($foundInQueue, 'Order should initially be in the manager approval queue.');

        // Eksekusi persetujuan dengan PIN supervisor yang valid
        $response = $this->actingAs($this->supervisorUser)
            ->postJson(route('dashboard.approve-ticket'), [
                'ticket_id' => $order->id,
                'ticket_type' => 'VOID',
                'decision' => 'approve',
                'supervisor_pin' => '5555',
                'reason' => 'Approved by supervisor on shift',
            ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        // Verifikasi order telah terotorisasi dengan voided_by disetel
        $order->refresh();
        $this->assertEquals(OrderStatus::Void, $order->order_status);
        $this->assertEquals($this->supervisorUser->id, (int) $order->voided_by);

        // Verifikasi order keluar dari antrean approval queue
        app(\App\Services\Context\TenantContext::class)->setTenantId($this->tenant->id);
        $mMetricsAfter = $managerService->getMetrics($this->supervisorUser, $this->store->id);
        $stillInQueue = collect($mMetricsAfter['approvalQueue'])->contains('ticket_no', 'ORD-VOID-TEST-99');
        $this->assertFalse($stillInQueue, 'Authorized void order must be cleared from the approval queue.');
    }

    public function test_supervisor_can_reject_void_order_and_it_clears_from_approval_queue(): void
    {
        $order = Order::withoutGlobalScopes()->forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->supervisorUser->id,
            'order_code' => 'ORD-VOID-REJECT-99',
            'idempotency_key' => 'IDEMP-REJECT-99',
            'order_amount' => 50000,
            'subtotal_amount' => 50000,
            'order_date' => Carbon::now('Asia/Jakarta')->toDateString(),
            'order_status' => OrderStatus::Void->value,
            'void_reason' => 'Customer changed mind',
            'voided_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(\App\Services\Context\TenantContext::class)->setTenantId($this->tenant->id);
        $managerService = app(ManagerDashboardService::class);
        $mMetricsBefore = $managerService->getMetrics($this->supervisorUser, $this->store->id);
        $foundInQueue = collect($mMetricsBefore['approvalQueue'])->contains('ticket_no', 'ORD-VOID-REJECT-99');
        $this->assertTrue($foundInQueue, 'Order should initially be in the manager approval queue.');

        $response = $this->actingAs($this->supervisorUser)
            ->postJson(route('dashboard.approve-ticket'), [
                'ticket_id' => $order->id,
                'ticket_type' => 'VOID',
                'decision' => 'reject',
                'supervisor_pin' => '5555',
                'reason' => 'Void rejected: meal already prepared and delivered',
            ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $order->refresh();
        $this->assertStringStartsWith('Ditolak:', (string) $order->void_reason);
        $this->assertNull($order->voided_by);

        app(\App\Services\Context\TenantContext::class)->setTenantId($this->tenant->id);
        $mMetricsAfter = $managerService->getMetrics($this->supervisorUser, $this->store->id);
        $stillInQueue = collect($mMetricsAfter['approvalQueue'])->contains('ticket_no', 'ORD-VOID-REJECT-99');
        $this->assertFalse($stillInQueue, 'Rejected void order must be cleared from the approval queue.');
    }

    public function test_supervisor_can_reject_paid_order_void_request(): void
    {
        $order = Order::withoutGlobalScopes()->forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->supervisorUser->id,
            'order_code' => 'ORD-PAID-REJECT-99',
            'idempotency_key' => 'IDEMP-PAID-REJECT-99',
            'order_amount' => 75000,
            'subtotal_amount' => 75000,
            'order_date' => Carbon::now('Asia/Jakarta')->toDateString(),
            'order_status' => OrderStatus::Paid->value,
            'void_reason' => 'Cashier requested void on paid order',
            'voided_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(\App\Services\Context\TenantContext::class)->setTenantId($this->tenant->id);
        $managerService = app(ManagerDashboardService::class);
        $mMetricsBefore = $managerService->getMetrics($this->supervisorUser, $this->store->id);
        $foundInQueue = collect($mMetricsBefore['approvalQueue'])->contains('ticket_no', 'ORD-PAID-REJECT-99');
        $this->assertTrue($foundInQueue, 'Paid order with void_reason should initially be in the approval queue.');

        $response = $this->actingAs($this->supervisorUser)
            ->postJson(route('dashboard.approve-ticket'), [
                'ticket_id' => $order->id,
                'ticket_type' => 'VOID',
                'decision' => 'reject',
                'supervisor_pin' => '5555',
                'reason' => 'Rejecting void on paid order: customer already left with food',
            ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $order->refresh();
        $this->assertEquals(OrderStatus::Paid, $order->order_status);
        $this->assertStringStartsWith('Ditolak:', (string) $order->void_reason);
        $this->assertNull($order->voided_by);

        app(\App\Services\Context\TenantContext::class)->setTenantId($this->tenant->id);
        $mMetricsAfter = $managerService->getMetrics($this->supervisorUser, $this->store->id);
        $stillInQueue = collect($mMetricsAfter['approvalQueue'])->contains('ticket_no', 'ORD-PAID-REJECT-99');
        $this->assertFalse($stillInQueue, 'Rejected paid void order must be cleared from the approval queue.');
    }

    public function test_supervisor_can_approve_shift_cash_variance_reconciliation_with_valid_pin(): void
    {
        $shift = Shift::withoutGlobalScopes()->forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->supervisorUser->id,
            'opened_at' => now()->subHours(8),
            'closed_at' => now()->subMinutes(10),
            'opening_balance' => 200000,
            'closing_balance' => 480000,
            'expected_cash' => 500000,
            'cash_difference' => -20000,
            'status' => 'closed',
            'variance_approved_by' => null,
            'variance_reason' => 'Selisih Kurang closing shift',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->supervisorUser)
            ->postJson(route('dashboard.approve-ticket'), [
                'ticket_id' => $shift->id,
                'ticket_type' => 'CASH_VARIANCE',
                'decision' => 'approve',
                'supervisor_pin' => '5555',
                'reason' => 'Discrepancy verified against physical cash bag',
            ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $shift->refresh();
        $this->assertEquals($this->supervisorUser->id, (int) $shift->variance_approved_by);
        $this->assertEquals('Discrepancy verified against physical cash bag', $shift->variance_reason);
    }

    public function test_approval_fails_with_invalid_supervisor_pin(): void
    {
        $shift = Shift::withoutGlobalScopes()->forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->supervisorUser->id,
            'opened_at' => now()->subHours(8),
            'closed_at' => now()->subMinutes(10),
            'opening_balance' => 200000,
            'closing_balance' => 450000,
            'expected_cash' => 500000,
            'cash_difference' => -50000,
            'status' => 'closed',
            'variance_approved_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->supervisorUser)
            ->postJson(route('dashboard.approve-ticket'), [
                'ticket_id' => $shift->id,
                'ticket_type' => 'CASH_VARIANCE',
                'decision' => 'approve',
                'supervisor_pin' => '0000', // Invalid PIN
                'reason' => 'Unauthorized override attempt',
            ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);

        $shift->refresh();
        $this->assertNull($shift->variance_approved_by);
    }

    public function test_approve_ticket_strictly_prevents_cross_tenant_manipulation(): void
    {
        $tenantB = Tenant::create([
            'name' => 'Foreign Tenant X',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'status' => 'active',
        ]);

        $storeB = Store::create([
            'tenant_id' => $tenantB->id,
            'name' => 'Foreign Branch',
        ]);

        $foreignRole = Role::firstOrCreate(
            ['name' => 'Supervisor', 'tenant_id' => $tenantB->id],
            [
                'role_code' => 'ROL-SUP-B',
                'permissions' => ['dashboard.action.approve_void_discount'],
            ]
        );

        $foreignUser = User::factory()->create([
            'tenant_id' => $tenantB->id,
            'role_id' => $foreignRole->id,
            'email' => 'foreign.cashier@tenantb.test',
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $foreignOrder = Order::withoutGlobalScopes()->forceCreate([
            'tenant_id' => $tenantB->id,
            'store_id' => $storeB->id,
            'user_id' => $foreignUser->id,
            'order_code' => 'ORD-FOREIGN-99',
            'idempotency_key' => 'IDEMP-FOREIGN-99',
            'order_amount' => 120000,
            'subtotal_amount' => 120000,
            'order_date' => Carbon::now('Asia/Jakarta')->toDateString(),
            'order_status' => OrderStatus::Void->value,
            'void_reason' => 'Foreign cancellation attempt',
            'voided_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->supervisorUser)
            ->postJson(route('dashboard.approve-ticket'), [
                'ticket_id' => $foreignOrder->id,
                'ticket_type' => 'VOID',
                'decision' => 'approve',
                'supervisor_pin' => '5555',
                'reason' => 'Cross-tenant attack attempt',
            ]);

        $response->assertNotFound();

        $foreignOrder->refresh();
        $this->assertNull($foreignOrder->voided_by);
        $this->assertEquals('Foreign cancellation attempt', $foreignOrder->void_reason);
    }

    public function test_unauthorized_user_cannot_save_store_targets(): void
    {
        $cashierRole = Role::firstOrCreate(
            ['name' => 'Kasir', 'tenant_id' => $this->tenant->id],
            [
                'role_code' => 'ROL-KASIR',
                'permissions' => ['orders.create', 'orders.view', 'payments.create', 'shifts.own'],
                'dashboard_archetype' => 'cashier_workspace',
                'is_system_default' => true,
                'is_active' => true,
            ]
        );

        $cashierUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $cashierRole->id,
            'email' => 'cashier.restricted@operational.test',
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $cashierUser->stores()->syncWithoutDetaching([$this->store->id => ['tenant_id' => $this->tenant->id]]);

        $response = $this->actingAs($cashierUser)
            ->postJson(route('dashboard.store-targets.save'), [
                'store_id' => $this->store->id,
                'target_period' => Carbon::now('Asia/Jakarta')->format('Y-m'),
                'target_net_sales' => 200000000,
            ]);

        $response->assertForbidden();
    }

    public function test_cross_tenant_role_duplication_is_strictly_rejected(): void
    {
        $tenantB = Tenant::create([
            'name' => 'Tenant Foreign Dup',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'status' => 'active',
        ]);

        $foreignRole = Role::create([
            'tenant_id' => $tenantB->id,
            'name' => 'Foreign Role',
            'role_code' => 'ROL-FOR-DUP',
            'permissions' => ['orders.create'],
            'dashboard_archetype' => 'branch_ops',
        ]);

        $response = $this->actingAs($this->ownerUser)
            ->post(route('roles.duplicate', $foreignRole->id));

        $response->assertNotFound();
    }
}
