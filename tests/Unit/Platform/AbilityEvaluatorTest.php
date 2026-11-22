<?php

declare(strict_types=1);

namespace Tests\Unit\Platform;

use App\Modules\Organization\Models\Role;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Models\User;
use App\Platform\Authorization\AbilityEvaluator;
use App\Platform\Authorization\AbilityRegistry;
use App\Platform\Authorization\Scope;
use Tests\TestCase;

class AbilityEvaluatorTest extends TestCase
{
    private AbilityRegistry $registry;

    private AbilityEvaluator $evaluator;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();
        $this->registry = new AbilityRegistry;
        $this->evaluator = new AbilityEvaluator($this->registry);
    }

    public function test_scope_rank_and_satisfaction(): void
    {
        $this->assertTrue(Scope::TENANT->satisfies(Scope::COMPANY));
        $this->assertTrue(Scope::TENANT->satisfies(Scope::BRANCH));
        $this->assertTrue(Scope::COMPANY->satisfies(Scope::BRANCH));
        $this->assertTrue(Scope::BRANCH->satisfies(Scope::SELF));
        $this->assertFalse(Scope::BRANCH->satisfies(Scope::COMPANY));
        $this->assertFalse(Scope::SELF->satisfies(Scope::BRANCH));
    }

    public function test_registry_registers_core_module_abilities(): void
    {
        $this->assertTrue($this->registry->has('sales.create'));
        $this->assertTrue($this->registry->has('sales.void'));
        $this->assertTrue($this->registry->has('inventory.transfer'));
        $this->assertTrue($this->registry->has('finance.close_period'));

        $salesAbilities = $this->registry->byModule('sales');
        $this->assertNotEmpty($salesAbilities);
        foreach ($salesAbilities as $ability) {
            $this->assertEquals('sales', $ability->module);
        }
    }

    public function test_inactive_user_is_denied(): void
    {
        $user = new User;
        $user->is_active = false;
        $user->tenant_id = 1;

        $this->assertFalse($this->evaluator->can($user, 'sales.create'));
    }

    public function test_tenant_mismatch_is_denied_fail_closed(): void
    {
        $user = new User;
        $user->is_active = true;
        $user->tenant_id = 1;

        $this->assertFalse($this->evaluator->can($user, 'sales.create', ['tenant_id' => 2]));
    }

    public function test_owner_or_superadmin_bypasses_within_tenant(): void
    {
        $role = new Role;
        $role->name = 'Owner';
        $role->is_admin = true;

        $user = new User;
        $user->is_active = true;
        $user->tenant_id = 1;
        $user->setRelation('role', $role);

        $this->assertTrue($this->evaluator->can($user, 'sales.void', ['tenant_id' => 1]));
    }

    public function test_user_without_permission_is_denied(): void
    {
        $role = new Role;
        $role->name = 'Kasir';
        $role->is_admin = false;
        $role->permissions = ['sales.create', 'sales.view'];

        $store = new Store;
        $store->id = 10;

        $user = new User;
        $user->is_active = true;
        $user->tenant_id = 1;
        $user->setRelation('role', $role);
        $user->setRelation('stores', collect([$store]));

        $this->assertTrue($this->evaluator->can($user, 'sales.create', ['store_id' => 10]));
        $this->assertFalse($this->evaluator->can($user, 'sales.refund', ['store_id' => 10]));
    }

    public function test_approval_requirement_is_enforced(): void
    {
        $role = new Role;
        $role->name = 'Kasir';
        $role->is_admin = false;
        $role->permissions = ['sales.void'];

        $store = new Store;
        $store->id = 10;

        $user = new User;
        $user->is_active = true;
        $user->tenant_id = 1;
        $user->setRelation('role', $role);
        $user->setRelation('stores', collect([$store]));

        // Without approval_id context, cashier cannot void
        $this->assertFalse($this->evaluator->can($user, 'sales.void', ['store_id' => 10]));

        // With approval_id context, cashier can void
        $this->assertTrue($this->evaluator->can($user, 'sales.void', ['store_id' => 10, 'approval_id' => 999]));
    }

    public function test_branch_scope_enforcement(): void
    {
        $role = new Role;
        $role->name = 'Kasir';
        $role->is_admin = false;
        $role->permissions = ['sales.create'];

        $store = new Store;
        $store->id = 10;

        $user = new User;
        $user->is_active = true;
        $user->tenant_id = 1;
        $user->setRelation('role', $role);
        $user->setRelation('stores', collect([$store]));

        // Matches assigned branch
        $this->assertTrue($this->evaluator->can($user, 'sales.create', ['store_id' => 10]));

        // Different branch rejected
        $this->assertFalse($this->evaluator->can($user, 'sales.create', ['store_id' => 20]));
    }

    public function test_all_11_canonical_roles_resolve_correct_scope(): void
    {
        // 1. Tenant Scope Roles: Owner, Admin, Finance, HR / Workforce
        $tenantRoles = ['Owner', 'Admin', 'Finance', 'HR', 'Workforce'];
        foreach ($tenantRoles as $roleName) {
            $user = new User;
            $role = new Role;
            $role->name = $roleName;
            $role->is_admin = ($roleName === 'Owner');
            $user->setRelation('role', $role);

            $this->assertSame(
                Scope::TENANT,
                $this->evaluator->resolveUserScope($user),
                "Role {$roleName} must resolve to Scope::TENANT"
            );
        }

        // 2. Branch Scope Roles: Manager, Supervisor, Inventory, Purchasing, Sales
        $branchRoles = ['Manager', 'Supervisor', 'Inventory', 'Purchasing', 'Sales'];
        foreach ($branchRoles as $roleName) {
            $user = new User;
            $role = new Role;
            $role->name = $roleName;
            $role->is_admin = false;
            $user->setRelation('role', $role);

            $this->assertSame(
                Scope::BRANCH,
                $this->evaluator->resolveUserScope($user),
                "Role {$roleName} must resolve to Scope::BRANCH"
            );
        }

        // 3. Frontline Operational Roles: Kasir, Waiter, Cook, Barista
        $frontlineRoles = ['Kasir', 'Waiter', 'Cook', 'Barista'];
        $operationalAbility = $this->registry->get('sales.create');
        $selfAbility = $this->registry->get('hrm.leave');

        foreach ($frontlineRoles as $roleName) {
            $user = new User;
            $role = new Role;
            $role->name = $roleName;
            $role->is_admin = false;
            $user->setRelation('role', $role);

            // Operational ability or default -> Scope::BRANCH
            $this->assertSame(
                Scope::BRANCH,
                $this->evaluator->resolveUserScope($user, $operationalAbility),
                "Role {$roleName} must resolve to Scope::BRANCH for operational abilities"
            );
            $this->assertSame(
                Scope::BRANCH,
                $this->evaluator->resolveUserScope($user),
                "Role {$roleName} must default to Scope::BRANCH when no ability is specified"
            );

            // Personal self-service ability -> Scope::SELF
            $this->assertSame(
                Scope::SELF,
                $this->evaluator->resolveUserScope($user, $selfAbility),
                "Role {$roleName} must resolve to Scope::SELF for personal records"
            );
        }
    }

    public function test_self_scope_isolation_prevents_cross_user_access_for_frontline_roles(): void
    {
        $role = new Role;
        $role->name = 'Kasir';
        $role->is_admin = false;
        $role->permissions = ['hrm.leave'];

        $user = new User;
        $user->id = 42;
        $user->is_active = true;
        $user->tenant_id = 1;
        $user->setRelation('role', $role);

        // Own record is permitted
        $this->assertTrue(
            $this->evaluator->can($user, 'hrm.leave', ['user_id' => 42])
        );

        // Another user's record is blocked
        $this->assertFalse(
            $this->evaluator->can($user, 'hrm.leave', ['user_id' => 99])
        );
    }

    public function test_admin_is_subject_to_sod_hard_blocks_in_ability_evaluator(): void
    {
        $adminRole = new Role([
            'name' => 'Admin',
            'role_code' => 'ROLE-ADM',
            'is_system_default' => 1,
            'permissions' => ['*'],
        ]);

        $admin = new User([
            'id' => 101,
            'tenant_id' => 1,
            'is_active' => true,
        ]);
        $admin->setRelation('role', $adminRole);

        // Operational administrative ability is allowed
        $this->assertTrue($this->evaluator->can($admin, 'catalog.manage'));

        // SoD Hard-Blocks on Admin must be rejected (false)
        $this->assertFalse($this->evaluator->can($admin, 'subscription.manage'));
        $this->assertFalse($this->evaluator->can($admin, 'subscription.billing'));
        $this->assertFalse($this->evaluator->can($admin, 'finance.journal.post'));
        $this->assertFalse($this->evaluator->can($admin, 'finance.close_period'));
        $this->assertFalse($this->evaluator->can($admin, 'workforce.payroll.approve'));
        $this->assertFalse($this->evaluator->can($admin, 'hrm.payroll_approve'));

        // Conversely, Owner must bypass and be allowed
        $ownerRole = new Role([
            'name' => 'Owner',
            'role_code' => 'ROLE-OWN',
            'is_system_default' => 1,
            'is_admin' => true,
        ]);
        $owner = new User([
            'id' => 1,
            'tenant_id' => 1,
            'is_active' => true,
        ]);
        $owner->setRelation('role', $ownerRole);

        $this->assertTrue($this->evaluator->can($owner, 'subscription.manage'));
        $this->assertTrue($this->evaluator->can($owner, 'finance.journal.post'));
        $this->assertTrue($this->evaluator->can($owner, 'workforce.payroll.approve'));
    }

    public function test_branch_scope_permits_any_assigned_store_when_user_belongs_to_multiple_stores(): void
    {
        $role = new Role([
            'name' => 'Manager',
            'permissions' => ['sales.create'],
        ]);

        $user = new User([
            'id' => 50,
            'tenant_id' => 1,
            'is_active' => true,
        ]);
        $user->setRelation('role', $role);

        $store1 = new \App\Modules\Organization\Models\Store(['name' => 'Store 10']);
        $store1->id = 10;
        $store2 = new \App\Modules\Organization\Models\Store(['name' => 'Store 20']);
        $store2->id = 20;
        $user->setRelation('stores', collect([$store1, $store2]));

        // Accessing store 20 (second store) must succeed
        $this->assertTrue($this->evaluator->can($user, 'sales.create', ['store_id' => 20]));

        // Accessing store 10 (first store) must succeed
        $this->assertTrue($this->evaluator->can($user, 'sales.create', ['store_id' => 10]));

        // Accessing unassigned store 99 must fail
        $this->assertFalse($this->evaluator->can($user, 'sales.create', ['store_id' => 99]));
    }
}
