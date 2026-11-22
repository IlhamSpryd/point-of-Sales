<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use App\Core\Context\TenantContext;
use App\Enums\ApprovalStatusEnum;
use App\Livewire\Workflow\UnifiedApprovalInbox;
use App\Modules\Organization\Models\ActivityLog;
use App\Modules\Organization\Models\Role;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Models\Tenant;
use App\Modules\Organization\Models\User;
use App\Modules\Purchasing\Models\PurchaseApprovalRequest;
use App\Modules\Purchasing\Models\PurchaseApprovalRule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class UnifiedApprovalInboxTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;

    private Store $store;

    private User $makerUser;

    private User $checkerUser;

    private PurchaseApprovalRule $rule;

    protected function afterSubclassSetUp(): void
    {
        $context = app(TenantContext::class);

        $this->tenant = Tenant::create([
            'name' => 'Enterprise Workflow Tenant '.uniqid(),
            'slug' => 'wf-tenant-'.uniqid(),
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);

        $context->setTenantId($this->tenant->id);

        $this->store = Store::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Workflow Branch Headquarter',
            'is_active' => true,
        ]);

        $context->setStoreId($this->store->id);

        $ownerRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Owner',
            'permissions' => ['*'],
        ]);

        $managerRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Manager',
            'permissions' => ['*'],
        ]);

        $this->makerUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $managerRole->id,
            'name' => 'John Maker',
            'email' => 'maker-'.Str::random(6).'@apeiron.test',
            'email_verified_at' => now(),
        ]);

        $this->checkerUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $ownerRole->id,
            'name' => 'Alice Checker',
            'email' => 'checker-'.Str::random(6).'@apeiron.test',
            'email_verified_at' => now(),
        ]);

        foreach ([$this->makerUser, $this->checkerUser] as $u) {
            DB::table('user_stores')->insert([
                'tenant_id' => $this->tenant->id,
                'user_id' => $u->id,
                'store_id' => $this->store->id,
            ]);
        }

        $this->rule = PurchaseApprovalRule::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'document_type' => 'PR',
            'step_order' => 1,
            'store_id' => $this->store->id,
            'currency_code' => 'IDR',
            'min_amount' => '0.00',
            'max_amount' => '10000000.00',
            'approver_role_id' => $ownerRole->id,
            'is_active' => true,
        ]);
    }

    public function test_unified_approval_inbox_route_is_reachable_for_manager_and_owner(): void
    {
        $response = $this->actingAs($this->checkerUser)->get(route('erp.workflow.approvals'));
        $response->assertStatus(200);
        $response->assertSee('Kotak Masuk Persetujuan');
    }

    public function test_maker_checker_sod_prevents_self_approval(): void
    {
        // Maker creates an approval request
        $request = PurchaseApprovalRequest::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'approvable_type' => 'App\Modules\Purchasing\Models\PurchaseRequest',
            'approvable_id' => 101,
            'rule_id' => $this->rule->id,
            'step_order' => 1,
            'status' => ApprovalStatusEnum::Pending,
            'submitted_by_user_id' => $this->makerUser->id,
        ]);

        // Maker attempts to approve their own request via Livewire
        Livewire::actingAs($this->makerUser)
            ->test(UnifiedApprovalInbox::class)
            ->call('approve', $request->id)
            ->assertSee('Pelanggaran Pemisahan Tugas (SoD)');

        // Invariant: Status must remain pending and approver must remain null
        $request->refresh();
        $this->assertEquals(ApprovalStatusEnum::Pending, $request->status);
        $this->assertNull($request->approved_by_user_id);
    }

    public function test_maker_checker_sod_prevents_self_rejection(): void
    {
        $request = PurchaseApprovalRequest::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'approvable_type' => 'App\Modules\Purchasing\Models\PurchaseRequest',
            'approvable_id' => 102,
            'rule_id' => $this->rule->id,
            'step_order' => 1,
            'status' => ApprovalStatusEnum::Pending,
            'submitted_by_user_id' => $this->makerUser->id,
        ]);

        Livewire::actingAs($this->makerUser)
            ->test(UnifiedApprovalInbox::class)
            ->set('activeRejectingId', $request->id)
            ->set('rejectionReason', 'Membatalkan permohonan sendiri')
            ->call('confirmReject')
            ->assertSee('Pelanggaran Pemisahan Tugas (SoD)');

        $request->refresh();
        $this->assertEquals(ApprovalStatusEnum::Pending, $request->status);
    }

    public function test_legitimate_checker_can_approve_request_and_creates_audit_log(): void
    {
        $request = PurchaseApprovalRequest::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'approvable_type' => 'App\Modules\Purchasing\Models\PurchaseRequest',
            'approvable_id' => 103,
            'rule_id' => $this->rule->id,
            'step_order' => 1,
            'status' => ApprovalStatusEnum::Pending,
            'submitted_by_user_id' => $this->makerUser->id,
        ]);

        Livewire::actingAs($this->checkerUser)
            ->test(UnifiedApprovalInbox::class)
            ->call('approve', $request->id)
            ->assertHasNoErrors()
            ->assertSee("Permintaan persetujuan #{$request->id} berhasil disetujui.");

        $request->refresh();
        $this->assertEquals(ApprovalStatusEnum::Approved, $request->status);
        $this->assertEquals($this->checkerUser->id, $request->approved_by_user_id);
        $this->assertNotNull($request->decided_at);

        // Verify Immutable Audit Writer log entry
        $auditLog = ActivityLog::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('action', 'workflow.approval.approved')
            ->where('user_id', $this->checkerUser->id)
            ->first();

        $this->assertNotNull($auditLog);
        $this->assertStringContainsString((string) $request->id, (string) $auditLog->description);
    }

    public function test_legitimate_checker_can_reject_request_with_mandatory_reason(): void
    {
        $request = PurchaseApprovalRequest::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'approvable_type' => 'App\Modules\Purchasing\Models\PurchaseRequest',
            'approvable_id' => 104,
            'rule_id' => $this->rule->id,
            'step_order' => 1,
            'status' => ApprovalStatusEnum::Pending,
            'submitted_by_user_id' => $this->makerUser->id,
        ]);

        Livewire::actingAs($this->checkerUser)
            ->test(UnifiedApprovalInbox::class)
            ->set('activeRejectingId', $request->id)
            ->set('rejectionReason', 'Anggaran belanja modal untuk kuartal ini telah habis.')
            ->call('confirmReject')
            ->assertHasNoErrors()
            ->assertSee("Permintaan persetujuan #{$request->id} berhasil ditolak.");

        $request->refresh();
        $this->assertEquals(ApprovalStatusEnum::Rejected, $request->status);
        $this->assertEquals('Anggaran belanja modal untuk kuartal ini telah habis.', $request->rejection_reason);

        // Verify audit log entry
        $auditLog = ActivityLog::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('action', 'workflow.approval.rejected')
            ->where('user_id', $this->checkerUser->id)
            ->first();

        $this->assertNotNull($auditLog);
    }

    public function test_tenant_isolation_prevents_viewing_or_acting_on_cross_tenant_requests(): void
    {
        $context = app(TenantContext::class);

        // Switch to foreign tenant to create foreign record
        $otherTenant = Tenant::create([
            'name' => 'Foreign Corp '.uniqid(),
            'slug' => 'foreign-'.uniqid(),
            'status' => 'ACTIVE',
        ]);

        $context->setTenantId($otherTenant->id);

        $otherUser = User::factory()->create([
            'tenant_id' => $otherTenant->id,
        ]);

        $foreignRequest = PurchaseApprovalRequest::withoutGlobalScopes()->create([
            'tenant_id' => $otherTenant->id,
            'approvable_type' => 'App\Modules\Purchasing\Models\PurchaseRequest',
            'approvable_id' => 999,
            'rule_id' => null,
            'step_order' => 1,
            'status' => ApprovalStatusEnum::Pending,
            'submitted_by_user_id' => $otherUser->id,
        ]);

        // Restore our tenant context
        $context->setTenantId($this->tenant->id);

        // Attempting to approve foreign request must fail closed
        Livewire::actingAs($this->checkerUser)
            ->test(UnifiedApprovalInbox::class)
            ->call('approve', $foreignRequest->id)
            ->assertSee('tidak ditemukan');

        $foreignRequest->refresh();
        $this->assertEquals(ApprovalStatusEnum::Pending, $foreignRequest->status);
    }

    public function test_supervisor_can_access_inbox_and_is_blocked_from_po_expense_approvals(): void
    {
        $supervisorRole = Role::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'name' => 'Supervisor'],
            ['permissions' => ['orders.*', 'shifts.*', 'void.approve']]
        );

        $supervisorUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $supervisorRole->id,
            'name' => 'Sam Supervisor',
            'email' => 'supervisor-'.Str::random(6).'@apeiron.test',
            'email_verified_at' => now(),
        ]);

        DB::table('user_stores')->insert([
            'tenant_id' => $this->tenant->id,
            'user_id' => $supervisorUser->id,
            'store_id' => $this->store->id,
        ]);

        // 1. Supervisor route reachability
        $response = $this->actingAs($supervisorUser)->get(route('erp.workflow.approvals'));
        $response->assertStatus(200);

        // 2. PO Approval Request is blocked for Supervisor
        $poRequest = PurchaseApprovalRequest::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'approvable_type' => 'PurchaseOrder',
            'approvable_id' => 301,
            'rule_id' => null,
            'step_order' => 1,
            'status' => ApprovalStatusEnum::Pending,
            'submitted_by_user_id' => $this->makerUser->id,
        ]);

        Livewire::actingAs($supervisorUser)
            ->test(UnifiedApprovalInbox::class)
            ->call('approve', $poRequest->id)
            ->assertSee('Supervisor dibatasi hanya untuk persetujuan operasional');

        $poRequest->refresh();
        $this->assertEquals(ApprovalStatusEnum::Pending, $poRequest->status);
    }

    public function test_supervisor_inbox_is_strictly_scoped_to_assigned_branch(): void
    {
        $otherStore = Store::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Branch B - Remote',
            'is_active' => true,
        ]);

        $cashierRole = Role::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'name' => 'Kasir'],
            ['permissions' => ['orders.create']]
        );

        $otherMaker = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $cashierRole->id,
            'name' => 'Cashier Store B',
            'email' => 'cashier-b-'.Str::random(6).'@apeiron.test',
            'email_verified_at' => now(),
        ]);

        DB::table('user_stores')->insert([
            'tenant_id' => $this->tenant->id,
            'user_id' => $otherMaker->id,
            'store_id' => $otherStore->id,
        ]);

        $supervisorRole = Role::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'name' => 'Supervisor'],
            ['permissions' => ['orders.*', 'shifts.*', 'void.approve']]
        );

        $supervisorUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $supervisorRole->id,
            'name' => 'Supervisor Store A',
            'email' => 'sup-a-'.Str::random(6).'@apeiron.test',
            'email_verified_at' => now(),
        ]);

        DB::table('user_stores')->insert([
            'tenant_id' => $this->tenant->id,
            'user_id' => $supervisorUser->id,
            'store_id' => $this->store->id,
        ]);

        // Operational approval from other store (Store B)
        $remoteRequest = PurchaseApprovalRequest::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'approvable_type' => 'OrderVoid',
            'approvable_id' => 909,
            'rule_id' => null,
            'step_order' => 1,
            'status' => ApprovalStatusEnum::Pending,
            'submitted_by_user_id' => $otherMaker->id,
        ]);

        // Supervisor of Store A must NOT see Store B requests in inbox
        Livewire::actingAs($supervisorUser)
            ->test(UnifiedApprovalInbox::class)
            ->assertDontSee('#909')
            ->call('approve', $remoteRequest->id)
            ->assertSee('Supervisor hanya dapat menyetujui permohonan pada cabang yang ditugaskan');

        $remoteRequest->refresh();
        $this->assertEquals(ApprovalStatusEnum::Pending, $remoteRequest->status);
    }
}
