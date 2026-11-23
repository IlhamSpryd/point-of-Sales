<?php

declare(strict_types=1);

namespace Tests\Feature\Reports\E2e\Tier1;

use App\Core\Context\TenantContext;
use App\Platform\Authorization\AbilityEvaluator;
use App\Platform\Authorization\AbilityRegistry;
use Tests\Feature\Reports\E2e\BaseE2eReportTestCase;

class DashboardAuthorizationAndAbilitiesTest extends BaseE2eReportTestCase
{
    protected AbilityRegistry $abilityRegistry;

    protected AbilityEvaluator $abilityEvaluator;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->abilityRegistry = app(AbilityRegistry::class);
        $this->abilityEvaluator = app(AbilityEvaluator::class);
    }

    /**
     * T1-FE07-01: Verify ability registry contains all 10 dashboard abilities.
     */
    public function test_ability_registry_contains_all_10_dashboard_abilities(): void
    {
        $expectedAbilities = [
            'dashboard.view',
            'dashboard.scope.all_stores',
            'dashboard.metric.revenue',
            'dashboard.metric.profit_margin',
            'dashboard.metric.finance_gl',
            'dashboard.action.approve_void_discount',
            'dashboard.perspective.branch_ops',
            'dashboard.perspective.cashier',
            'dashboard.perspective.inventory',
            'dashboard.perspective.finance',
        ];

        foreach ($expectedAbilities as $abilityName) {
            $this->assertTrue(
                $this->abilityRegistry->has($abilityName),
                "AbilityRegistry must contain ability: {$abilityName}"
            );
        }
    }

    /**
     * T1-FE07-02: Verify ability evaluator grants access to owner unconditionally.
     */
    public function test_ability_evaluator_grants_access_to_owner_unconditionally(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->assertTrue(
            $this->abilityEvaluator->can($this->ownerUser, 'dashboard.view'),
            'Owner must have access to dashboard.view'
        );
        $this->assertTrue(
            $this->abilityEvaluator->can($this->ownerUser, 'dashboard.scope.all_stores'),
            'Owner must have access to dashboard.scope.all_stores'
        );
        $this->assertTrue(
            $this->abilityEvaluator->can($this->ownerUser, 'dashboard.metric.profit_margin'),
            'Owner must have access to dashboard.metric.profit_margin'
        );
        $this->assertTrue(
            $this->abilityEvaluator->can($this->ownerUser, 'dashboard.perspective.finance'),
            'Owner must have access to dashboard.perspective.finance'
        );
    }

    /**
     * T1-FE07-03: Verify ability evaluator restricts branch manager to assigned store.
     */
    public function test_ability_evaluator_restricts_branch_manager_to_assigned_store(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $storeA = $this->store;
        $storeB = $this->createStore($this->tenant, ['name' => 'Branch B']);

        $managerUser = $this->createUserWithRole(
            $this->tenant,
            'Branch Manager',
            ['dashboard.view', 'dashboard.metric.revenue'],
            $storeA,
            'branch_ops'
        );

        $this->assertTrue(
            $this->abilityEvaluator->can($managerUser, 'dashboard.view', ['store_id' => $storeA->id]),
            'Branch Manager must be authorized for assigned Store A.'
        );

        $this->assertFalse(
            $this->abilityEvaluator->can($managerUser, 'dashboard.scope.all_stores'),
            'Branch Manager without all_stores permission must not have dashboard.scope.all_stores.'
        );

        $this->assertFalse(
            $this->abilityEvaluator->can($managerUser, 'dashboard.view', ['store_id' => $storeB->id]),
            'Branch Manager must not be authorized for unassigned Store B.'
        );
    }

    /**
     * T1-FE07-04: Verify ability evaluator restricts profit margin metric when permission is missing.
     */
    public function test_ability_evaluator_restricts_profit_margin_metric_by_permission(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $staffUser = $this->createUserWithRole(
            $this->tenant,
            'Kasir',
            ['dashboard.view', 'dashboard.metric.revenue'],
            $this->store,
            'cashier_workspace'
        );

        $this->assertTrue(
            $this->abilityEvaluator->can($staffUser, 'dashboard.metric.revenue'),
            'Staff with dashboard.metric.revenue must evaluate to true.'
        );

        $this->assertFalse(
            $this->abilityEvaluator->can($staffUser, 'dashboard.metric.profit_margin'),
            'Staff lacking dashboard.metric.profit_margin must evaluate to false.'
        );
    }

    /**
     * T1-FE07-05: Verify ability evaluator fails closed on inactive user.
     */
    public function test_ability_evaluator_fails_closed_on_inactive_user(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $inactiveUser = $this->createUserWithRole(
            $this->tenant,
            'Deactivated Staff',
            ['*'],
            $this->store,
            'executive',
            ['is_active' => false]
        );

        $this->assertFalse(
            $this->abilityEvaluator->can($inactiveUser, 'dashboard.view'),
            'Deactivated user must fail closed on dashboard.view.'
        );
        $this->assertFalse(
            $this->abilityEvaluator->can($inactiveUser, 'dashboard.metric.revenue'),
            'Deactivated user must fail closed on dashboard.metric.revenue.'
        );
    }
}
