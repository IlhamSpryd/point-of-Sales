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
}
