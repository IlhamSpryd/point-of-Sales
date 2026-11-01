<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\CashDrawerMovement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Services\Context\TenantContext;
use App\Services\ShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

class ShiftServiceTest extends TestCase
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

    public function test_get_cash_summary_calculates_correct_totals(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $shift = Shift::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->userAOwner->id,
            'opening_balance' => 100000,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $order = Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'shift_id' => $shift->id,
            'user_id' => $this->userAOwner->id,
            'order_code' => 'TEST-SHIFT-ORD-1',
            'idempotency_key' => (string) Str::uuid(),
            'order_date' => now()->toDateString(),
            'order_type' => 'dine_in',
            'order_status' => 'paid',
            'subtotal_amount' => 50000,
            'tax_amount' => 5500,
            'order_amount' => 55500,
        ]);

        Payment::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'order_id' => $order->id,
            'payment_method' => 'cash',
            'amount' => 55500,
            'status' => 'captured',
        ]);

        CashDrawerMovement::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'shift_id' => $shift->id,
            'type' => 'cash_in',
            'category' => 'restock_change',
            'amount' => 20000,
            'reason' => 'Modal kembalian tambahan',
            'created_by' => $this->userAOwner->id,
        ]);

        CashDrawerMovement::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'shift_id' => $shift->id,
            'type' => 'cash_out',
            'category' => 'petty_expense',
            'amount' => 10000,
            'reason' => 'Beli es batu darurat',
            'created_by' => $this->userAOwner->id,
        ]);

        $service = app(ShiftService::class);
        $summary = $service->getCashSummary($shift);

        $this->assertEquals(55500, $summary['cash_sales']);
        $this->assertEquals(20000, $summary['cash_in']);
        $this->assertEquals(10000, $summary['cash_out']);
        // 100,000 + 55,500 + 20,000 - 10,000 = 165,500
        $this->assertEquals(165500, $summary['expected_cash']);
    }

    public function test_close_shift_updates_shift_record_atomically(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $shift = Shift::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->userAOwner->id,
            'opening_balance' => 100000,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $service = app(ShiftService::class);
        $closedShift = $service->closeShift($this->userAOwner->id, 105000, 'Selisih lebih 5000');

        $this->assertNotNull($closedShift);
        $this->assertEquals('closed', $closedShift->status);
        $this->assertEquals(105000, $closedShift->closing_balance);
        $this->assertEquals(100000, $closedShift->expected_cash);
        $this->assertEquals(5000, $closedShift->cash_difference);
        $this->assertEquals('Selisih lebih 5000', $closedShift->notes);
        $this->assertNotNull($closedShift->closed_at);
    }

    public function test_close_shift_returns_null_when_no_active_shift(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $service = app(ShiftService::class);
        $closedShift = $service->closeShift($this->userAOwner->id, 100000);

        $this->assertNull($closedShift);
    }

    public function test_shift_supervisor_authorization_strictly_rejects_cross_tenant_store(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $service = app(ShiftService::class);

        // 1. Owner of Tenant A is rejected when attempting to authorize Store B belonging to Tenant B
        $canAuthStoreB = $service->canAuthorizeSupervisor($this->userAOwner, $this->storeB->id);
        $this->assertFalse($canAuthStoreB, 'Owner of Tenant A must NEVER authorize Store B of Tenant B');

        // 2. Owner of Tenant A is authorized for Store A belonging to Tenant A
        $canAuthStoreA = $service->canAuthorizeSupervisor($this->userAOwner, $this->storeA->id);
        $this->assertTrue($canAuthStoreA, 'Owner of Tenant A must be authorized for own store');

        // 3. Supervisor of Tenant A assigned to Store A
        $roleSupervisorA = Role::withoutTenantScope()->firstOrCreate(
            ['name' => 'Supervisor', 'tenant_id' => $this->tenantA->id],
            ['is_active' => 1]
        );
        $supervisorA = User::create([
            'name' => 'Supervisor A',
            'email' => 'spvA_test@example.com',
            'password' => bcrypt('password'),
            'pin_hash' => bcrypt('123456'),
            'tenant_id' => $this->tenantA->id,
            'role_id' => $roleSupervisorA->id,
            'is_active' => 1,
        ]);
        $supervisorA->stores()->attach($this->storeA->id, ['tenant_id' => $this->tenantA->id]);

        // Supervisor A can authorize assigned Store A
        $this->assertTrue($service->canAuthorizeSupervisor($supervisorA, $this->storeA->id));

        // Supervisor A CANNOT authorize Store B belonging to Tenant B
        $this->assertFalse($service->canAuthorizeSupervisor($supervisorA, $this->storeB->id));
    }

    public function test_shift_service_fails_closed_when_tenant_is_missing_or_invalid(): void
    {
        $context = app(TenantContext::class);
        $context->clear();

        $service = app(ShiftService::class);

        $invalidUser = new User;
        $invalidUser->id = 8888;
        $invalidUser->tenant_id = null;

        // 1. canAuthorizeSupervisor fails closed when tenant context is missing
        $threwDomainException = false;
        try {
            $service->canAuthorizeSupervisor($invalidUser, $this->storeA->id);
        } catch (\DomainException $e) {
            $threwDomainException = true;
        }
        $this->assertTrue($threwDomainException, 'canAuthorizeSupervisor must throw DomainException when tenant cannot be resolved');

        // 2. openShift fails closed when tenant context is missing
        $this->expectException(\DomainException::class);
        $service->openShift(0, 0);
    }
}
