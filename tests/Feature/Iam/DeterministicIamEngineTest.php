<?php

declare(strict_types=1);

namespace Tests\Feature\Iam;

use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserStoreRole;
use App\Services\Context\TenantContext;
use App\Services\Iam\AuthorizationResult;
use App\Services\Iam\DeterministicAuthorizationEngine;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DeterministicIamEngineTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;
    private Store $storeA;
    private Store $storeB;
    private Role $cashierRole;
    private Role $inventorySupervisorRole;
    private Role $managerRole;
    private Role $disciplinedRole;
    private DeterministicAuthorizationEngine $engine;

    protected bool $optOutFromDefaultTenant = true;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = Tenant::create([
            'name' => 'Apeiron Enterprise IAM Test',
            'slug' => 'apeiron-iam-'.uniqid(),
            'status' => 'active',
            'country_code' => 'ID',
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->storeA = Store::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Store Jakarta A',
            'is_active' => true,
        ]);

        $this->storeB = Store::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Store Bandung B',
            'is_active' => true,
        ]);

        $this->cashierRole = Role::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cashier',
            'permissions' => ['pos.checkout', 'orders.create', 'pos.discount.manual_apply'],
        ]);

        $this->inventorySupervisorRole = Role::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Inventory Supervisor',
            'permissions' => ['inventory.adjustment', 'stock_opname.record', 'inventory.read'],
        ]);

        $this->managerRole = Role::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Manager',
            'permissions' => ['pos.checkout', 'orders.create', 'orders.void', 'pos.discount.manual_apply'],
        ]);

        $this->disciplinedRole = Role::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Discipline Policy',
            'permissions' => ['DENY:orders.discount'],
        ]);

        $this->engine = app(DeterministicAuthorizationEngine::class);
    }

    /**
     * Skenario A & Test Matrix #1: Multi-Role Discount Cap (Anti-MAX Privilege Escalation)
     * Staf memegang Kasir (5% Limit) dan Supervisor Gudang (20% Threshold).
     * Saat checkout di POS (domain sales), batas mandiri kasir maksimal adalah 5%.
     * Diskon 15% wajib membutuhkan approval supervisor!
     */
    public function test_multi_role_discount_cap_prevents_privilege_escalation(): void
    {
        $user = User::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->storeA->id,
            'role_id' => $this->cashierRole->id,
            'name' => 'Budi Kasir Gudang',
            'email' => 'budi.'.uniqid().'@apeiron.test',
            'password' => bcrypt('secret123'),
        ]);

        // Assign both roles in Store A
        UserStoreRole::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'store_id' => $this->storeA->id,
            'role_id' => $this->cashierRole->id,
            'is_active' => true,
        ]);

        UserStoreRole::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'store_id' => $this->storeA->id,
            'role_id' => $this->inventorySupervisorRole->id,
            'is_active' => true,
        ]);

        // 1. Checkout with 5% discount (Within safe autonomous limit)
        $resultSafe = $this->engine->evaluate($user, 'pos.discount.manual_apply', [
            'domain' => 'sales',
            'store_id' => $this->storeA->id,
            'requested_percentage' => 5.0,
            'requested_amount' => 5000.0,
        ]);

        $this->assertTrue($resultSafe->isAllowed);
        $this->assertFalse($resultSafe->requiresApproval);

        // 2. Checkout with 15% discount (Exceeds cashier 5% limit -> Requires Approval, NOT escalated to 20%!)
        $resultEscalated = $this->engine->evaluate($user, 'pos.discount.manual_apply', [
            'domain' => 'sales',
            'store_id' => $this->storeA->id,
            'requested_percentage' => 15.0,
            'requested_amount' => 15000.0,
        ]);

        $this->assertFalse($resultEscalated->isAllowed);
        $this->assertTrue($resultEscalated->requiresApproval);
        $this->assertSame(AuthorizationResult::CODE_APPROVAL_REQUIRED, $resultEscalated->code);
        $this->assertSame(5.0, $resultEscalated->maxAllowedValue);
    }

    /**
     * Skenario B & Test Matrix #2: Cross-Store Role Isolation
     * User = Supervisor di Store B, Kasir di Store A.
     * Login di Store A -> Hak void/supervisor Store B TIDAK AKTIF di Store A.
     */
    public function test_cross_store_role_isolation(): void
    {
        $user = User::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->storeA->id,
            'role_id' => $this->cashierRole->id,
            'name' => 'Siti Dual Branch',
            'email' => 'siti.'.uniqid().'@apeiron.test',
            'password' => bcrypt('secret123'),
        ]);

        // Kasir di Store A
        UserStoreRole::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'store_id' => $this->storeA->id,
            'role_id' => $this->cashierRole->id,
            'is_active' => true,
        ]);

        // Manager di Store B
        UserStoreRole::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'store_id' => $this->storeB->id,
            'role_id' => $this->managerRole->id,
            'is_active' => true,
        ]);

        // In Store A context: Cannot void order (only Manager has orders.void, but Manager is in Store B)
        $resultInStoreA = $this->engine->evaluate($user, 'orders.void', [
            'store_id' => $this->storeA->id,
        ]);

        $this->assertFalse($resultInStoreA->isAllowed);
        $this->assertSame(AuthorizationResult::CODE_DEFAULT_DENY, $resultInStoreA->code);

        // In Store B context: Manager CAN void order
        $resultInStoreB = $this->engine->evaluate($user, 'orders.void', [
            'store_id' => $this->storeB->id,
        ]);

        $this->assertTrue($resultInStoreB->isAllowed);
    }

    /**
     * Skenario C: Temporal Validity Expiration
     * Penugasan peran sementara yang sudah lewat tanggal valid_to ditolak.
     */
    public function test_temporal_validity_expiration(): void
    {
        $user = User::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->storeA->id,
            'name' => 'Auditor Sementara',
            'email' => 'auditor.'.uniqid().'@apeiron.test',
            'password' => bcrypt('secret123'),
        ]);

        // Expired assignment (ended yesterday)
        UserStoreRole::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'store_id' => $this->storeA->id,
            'role_id' => $this->inventorySupervisorRole->id,
            'valid_from' => now()->subDays(10)->toDateString(),
            'valid_to' => now()->subDays(1)->toDateString(),
            'is_active' => true,
        ]);

        $result = $this->engine->evaluate($user, 'inventory.adjustment', [
            'store_id' => $this->storeA->id,
        ]);

        $this->assertFalse($result->isAllowed);
        $this->assertSame(AuthorizationResult::CODE_TEMPORAL_EXPIRED, $result->code);
    }

    /**
     * Skenario D: Explicit Deny Precedence
     * Role A memberikan orders.discount, Role B memberikan DENY:orders.discount.
     * Evaluasi larangan eksplisit menang mutlak (Fail-closed).
     */
    public function test_explicit_deny_precedence(): void
    {
        $user = User::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->storeA->id,
            'role_id' => $this->cashierRole->id,
            'name' => 'Kasir Kena Sanksi',
            'email' => 'sanksi.'.uniqid().'@apeiron.test',
            'password' => bcrypt('secret123'),
        ]);

        UserStoreRole::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'store_id' => $this->storeA->id,
            'role_id' => $this->cashierRole->id,
            'is_active' => true,
        ]);

        UserStoreRole::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'store_id' => $this->storeA->id,
            'role_id' => $this->disciplinedRole->id,
            'is_active' => true,
        ]);

        $result = $this->engine->evaluate($user, 'orders.discount', [
            'store_id' => $this->storeA->id,
        ]);

        $this->assertFalse($result->isAllowed);
        $this->assertSame(AuthorizationResult::CODE_EXPLICIT_DENY, $result->code);
    }

    /**
     * Segregation of Duties (SoD): Anti-Self Approval
     */
    public function test_segregation_of_duties_prevents_self_approval(): void
    {
        $user = User::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->storeA->id,
            'role_id' => $this->managerRole->id,
            'name' => 'Manager Self Approver',
            'email' => 'manager.'.uniqid().'@apeiron.test',
            'password' => bcrypt('secret123'),
        ]);

        UserStoreRole::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'store_id' => $this->storeA->id,
            'role_id' => $this->managerRole->id,
            'is_active' => true,
        ]);

        $result = $this->engine->evaluate($user, 'pos.discount.manual_apply', [
            'store_id' => $this->storeA->id,
            'is_maker' => true,
            'target_user_id' => $user->id, // Requester attempting to approve own discount
        ]);

        $this->assertFalse($result->isAllowed);
        $this->assertSame(AuthorizationResult::CODE_SOD_VIOLATION, $result->code);
    }

    /**
     * Scope Validation: User with store A assignment is strictly denied in Store B context.
     */
    public function test_user_strictly_denied_in_unassigned_store_context(): void
    {
        $user = User::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->storeA->id,
            'role_id' => $this->cashierRole->id,
            'name' => 'Single Store Staff',
            'email' => 'single.'.uniqid().'@apeiron.test',
            'password' => bcrypt('secret123'),
        ]);

        // Explicitly assigned ONLY to Store A
        UserStoreRole::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'store_id' => $this->storeA->id,
            'role_id' => $this->cashierRole->id,
            'is_active' => true,
        ]);

        // Requesting action in Store B must be strictly denied with CODE_SCOPE_MISMATCH
        $result = $this->engine->evaluate($user, 'orders.create', [
            'store_id' => $this->storeB->id,
        ]);

        $this->assertFalse($result->isAllowed);
        $this->assertSame(AuthorizationResult::CODE_SCOPE_MISMATCH, $result->code);
    }
}
