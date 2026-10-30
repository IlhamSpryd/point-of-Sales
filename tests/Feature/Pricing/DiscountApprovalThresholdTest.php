<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use App\Models\Approval;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Pricing\Services\DiscountApprovalService;
use App\Modules\Users\Services\CashierPinService;
use App\Services\Context\TenantContext;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiscountApprovalThresholdTest extends TestCase
{
    use RefreshDatabase;

    private DiscountApprovalService $service;

    private Tenant $tenant;

    private Store $store;

    private User $cashier;

    private User $supervisor;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->service = app(DiscountApprovalService::class);

        $this->tenant = Tenant::create([
            'name' => 'Threshold Test Tenant',
            'slug' => 'threshold-test-tenant',
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Store Threshold',
            'is_active' => true,
        ]);

        app(TenantContext::class)->setStoreId($this->store->id);

        $cashierRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Kasir',
            'permissions' => ['orders.create'],
        ]);

        $supervisorRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Supervisor',
            'permissions' => ['orders.create', 'discount.approve'],
        ]);

        $this->cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'role_id' => $cashierRole->id,
        ]);
        app(CashierPinService::class)->setPin($this->cashier, '1111');

        $this->supervisor = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'role_id' => $supervisorRole->id,
        ]);
        app(CashierPinService::class)->setPin($this->supervisor, '9999');
    }

    public function test_threshold_evaluation_identifies_safe_and_unsafe_discounts(): void
    {
        // 4% dan Rp8.000: Aman (<= 5% dan <= Rp10.000) -> tidak butuh approval
        $this->assertFalse($this->service->evaluateThreshold(4.0, 8000.0, 200000.0));

        // Tepat 5% dan Rp10.000: Batas aman PRD OD-13 -> tidak butuh approval
        $this->assertFalse($this->service->evaluateThreshold(5.0, 10000.0, 200000.0));

        // 8% dan Rp8.000: Melebihi 5% -> WAJIB approval
        $this->assertTrue($this->service->evaluateThreshold(8.0, 8000.0, 100000.0));

        // 3% dan Rp15.000: Melebihi Rp10.000 -> WAJIB approval
        $this->assertTrue($this->service->evaluateThreshold(3.0, 15000.0, 500000.0));
    }

    public function test_supervisor_approval_creation_and_atomic_consumption(): void
    {
        // Buat approval dengan PIN Supervisor '9999'
        $approval = $this->service->createApproval(
            requesterId: $this->cashier->id,
            pin: '9999',
            action: Approval::ACTION_MANUAL_DISCOUNT,
            amount: 25000.0,
            reason: 'Pelanggan Setia Komplain Keterlambatan',
            storeId: $this->store->id,
            approverId: $this->supervisor->id
        );

        $this->assertEquals(Approval::STATUS_APPROVED, $approval->status);
        $this->assertEquals($this->cashier->id, $approval->requester_user_id);
        $this->assertEquals($this->supervisor->id, $approval->approver_user_id);

        // Konsumsi token persetujuan saat checkout order #888
        $this->service->consumeApproval($approval->id, 888);

        $this->assertEquals(Approval::STATUS_CONSUMED, $approval->fresh()->status);
        $this->assertEquals(888, $approval->fresh()->consumed_by_order_id);
        $this->assertNotNull($approval->fresh()->consumed_at);

        // Percobaan konsumsi token untuk kedua kalinya harus ditolak secara atomik
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage("Token approval #{$approval->id} sudah pernah digunakan");

        $this->service->consumeApproval($approval->id, 999);
    }
}
