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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AntiSelfApprovalSecurityTest extends TestCase
{
    use RefreshDatabase;

    private DiscountApprovalService $service;

    private Tenant $tenant;

    private Store $store;

    private User $supervisorCashier;

    private User $independentManager;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->service = app(DiscountApprovalService::class);

        $this->tenant = Tenant::create([
            'name' => 'Anti Self Approval Tenant',
            'slug' => 'anti-self-approval-tenant',
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cabang Keamanan',
            'is_active' => true,
        ]);

        app(TenantContext::class)->setStoreId($this->store->id);

        $supervisorRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Supervisor',
            'permissions' => ['orders.create', 'discount.approve'],
        ]);

        $managerRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Manager',
            'permissions' => ['orders.create', 'discount.approve'],
        ]);

        // User 1: Supervisor yang sedang berjaga di POS kasir
        $this->supervisorCashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'role_id' => $supervisorRole->id,
        ]);
        app(CashierPinService::class)->setPin($this->supervisorCashier, '5555');

        // User 2: Manager independen
        $this->independentManager = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'role_id' => $managerRole->id,
        ]);
        app(CashierPinService::class)->setPin($this->independentManager, '8888');
    }

    public function test_self_approval_is_blocked_when_supervisor_uses_own_pin_for_own_request(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Self-approval dilarang.');

        // Supervisor yang sedang transaksi di kasir mencoba meng-approve diskonnya sendiri dengan PIN '5555' miliknya
        $this->service->createApproval(
            requesterId: $this->supervisorCashier->id,
            pin: '5555',
            action: Approval::ACTION_MANUAL_DISCOUNT,
            amount: 30000.0,
            reason: 'Mencoba diskon sendiri',
            storeId: $this->store->id,
            approverId: $this->supervisorCashier->id // Mencoba menyetujui diri sendiri
        );
    }

    public function test_self_approval_is_blocked_even_when_approver_id_is_not_explicitly_passed(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Self-approval dilarang.');

        // Mencoba memasukkan PIN diri sendiri tanpa oper approverId
        $this->service->createApproval(
            requesterId: $this->supervisorCashier->id,
            pin: '5555',
            action: Approval::ACTION_MANUAL_DISCOUNT,
            amount: 30000.0,
            reason: 'Mencoba bypass self-approval check',
            storeId: $this->store->id
        );
    }

    public function test_legitimate_approval_succeeds_when_approved_by_different_authorized_user(): void
    {
        // Manager independen menginput PIN-nya '8888' untuk menyetujui transaksi kasir
        $approval = $this->service->createApproval(
            requesterId: $this->supervisorCashier->id,
            pin: '8888',
            action: Approval::ACTION_MANUAL_DISCOUNT,
            amount: 30000.0,
            reason: 'Otorisasi Sah Manager',
            storeId: $this->store->id,
            approverId: $this->independentManager->id
        );

        $this->assertNotNull($approval);
        $this->assertEquals(Approval::STATUS_APPROVED, $approval->status);
        $this->assertEquals($this->supervisorCashier->id, $approval->requester_user_id);
        $this->assertEquals($this->independentManager->id, $approval->approver_user_id);
        $this->assertNotEquals($approval->requester_user_id, $approval->approver_user_id);
    }
}
