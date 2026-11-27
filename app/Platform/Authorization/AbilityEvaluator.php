<?php

declare(strict_types=1);

namespace App\Platform\Authorization;

use App\Modules\Organization\Models\User;

class AbilityEvaluator
{
    public function __construct(
        protected AbilityRegistry $registry
    ) {}

    /**
     * Evaluates whether a user can perform the requested ability within a specified context.
     * Context may include: tenant_id, company_id, store_id, approval_id, user_id (target).
     *
     * @param  array{tenant_id?: int|null, company_id?: int|null, store_id?: int|null, approval_id?: int|null, user_id?: int|null}  $context
     */
    public function can(User $user, string $abilityName, array $context = []): bool
    {
        // 1. Fail closed on inactive user
        if (! $user->is_active) {
            return false;
        }

        // 2. Multi-tenancy isolation guard
        if (isset($context['tenant_id']) && (int) $user->tenant_id !== (int) $context['tenant_id']) {
            return false;
        }

        // 3. Superadmin / Owner bypass within tenant
        $role = $user->role;
        $roleName = $role?->name;
        $isOwnerOrSuperadmin = in_array($roleName, ['Owner', 'Superadmin', 'Administrator'], true)
            || ((bool) ($role->is_admin ?? false) && ! in_array($roleName, ['Admin', 'ROLE-ADM'], true));
        if ($isOwnerOrSuperadmin) {
            return true;
        }

        // Segregation of Duties (SoD) hard-blocks on Admin (Decision 0.2 / ADR-NAV-009)
        if ($roleName === 'Admin' || $role?->role_code === 'ROLE-ADM') {
            if (str_starts_with($abilityName, 'subscription.') || in_array($abilityName, [
                'finance.journal.post',
                'finance.close_period',
                'hrm.payroll_approve',
                'workforce.payroll.approve',
            ], true)) {
                return false;
            }
        }

        // 4. Resolve registered Ability definition
        $ability = $this->registry->get($abilityName);

        // 5. Check role permissions (either explicit permission in role, or wildcards)
        $permissions = $role ? ($role->permissions ?? []) : [];
        $hasPermission = in_array($abilityName, $permissions, true)
            || in_array('*', $permissions, true)
            || ($ability !== null && in_array($ability->module.'.*', $permissions, true));

        if (! $hasPermission) {
            return false;
        }

        // If no custom ability definition registered, allow if role permission matched
        if ($ability === null) {
            return true;
        }

        // 6. Approval check if ability requires approval
        if ($ability->requiresApproval) {
            if (empty($context['approval_id'])) {
                if (! in_array($roleName, ['Manager', 'Supervisor'], true)) {
                    return false;
                }
            }
        }

        // 7. Scope enforcement
        return $this->evaluateScope($user, $ability->scope, $context, $ability);
    }

    /**
     * @param  array{tenant_id?: int|null, company_id?: int|null, store_id?: int|null, user_id?: int|null}  $context
     */
    protected function evaluateScope(User $user, Scope $requiredScope, array $context, ?Ability $ability = null): bool
    {
        $userScope = $this->resolveUserScope($user, $ability);

        if (! $userScope->satisfies($requiredScope)) {
            return false;
        }

        // Context-specific boundaries
        if ($requiredScope === Scope::BRANCH && isset($context['store_id'])) {
            $targetStoreId = (int) $context['store_id'];
            $hasStoreAccess = false;

            if ($user->relationLoaded('stores') && $user->stores->isNotEmpty()) {
                $hasStoreAccess = $user->stores->contains(static fn ($s): bool => (int) $s->id === $targetStoreId);
            } elseif ($user->exists) {
                $hasStoreAccess = $user->stores()->where('stores.id', $targetStoreId)->exists();
            }

            if ($userScope === Scope::BRANCH && ! $hasStoreAccess) {
                return false;
            }
        }

        if ($requiredScope === Scope::SELF && isset($context['user_id'])) {
            if ($userScope !== Scope::TENANT && (int) $user->id !== (int) $context['user_id']) {
                return false;
            }
        }

        return true;
    }

    public function resolveUserScope(User $user, ?Ability $ability = null): Scope
    {
        $roleName = $user->role?->name;

        // 1. Tenant Scope: Owner, Admin, Finance, HR / Workforce (tenant-wide visibility/operations)
        if ($user->role?->is_admin || in_array($roleName, ['Owner', 'Superadmin', 'Director', 'Admin', 'Administrator', 'Finance', 'Finance Manager', 'HR', 'Workforce', 'HR Manager'], true)) {
            return Scope::TENANT;
        }

        // 2. Company Scope: Multi-branch enterprise coordinators
        if (in_array($roleName, ['General Manager', 'Company Manager'], true)) {
            return Scope::COMPANY;
        }

        // 3. Branch Scope: Manager, Supervisor, Inventory, Purchasing, Sales (strictly scoped to assigned branch)
        if (in_array($roleName, ['Manager', 'Branch Manager', 'Store Manager', 'Supervisor', 'Inventory', 'Purchasing', 'Sales'], true)) {
            return Scope::BRANCH;
        }

        if ($user->role === null) {
            return Scope::SELF;
        }

        // 4. Frontline & Custom Operational Roles:
        // Scope::BRANCH for operational abilities; Scope::SELF for personal self-service records
        if ($ability !== null && ($ability->scope === Scope::SELF || str_ends_with($ability->name, '.own') || in_array($ability->name, ['hrm.leave', 'workforce.leave.apply', 'workforce.payslip.view_own'], true))) {
            return Scope::SELF;
        }

        return Scope::BRANCH;
    }
}
