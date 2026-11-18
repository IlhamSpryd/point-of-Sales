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
        if ($role?->is_admin || in_array($roleName, ['Owner', 'Superadmin', 'Administrator'], true)) {
            return true;
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
        return $this->evaluateScope($user, $ability->scope, $context);
    }

    /**
     * @param  array{tenant_id?: int|null, company_id?: int|null, store_id?: int|null, user_id?: int|null}  $context
     */
    protected function evaluateScope(User $user, Scope $requiredScope, array $context): bool
    {
        $userScope = $this->resolveUserScope($user);

        if (! $userScope->satisfies($requiredScope)) {
            return false;
        }

        // Context-specific boundaries
        if ($requiredScope === Scope::BRANCH && isset($context['store_id'])) {
            $userStoreId = null;
            if ($user->relationLoaded('stores') && $user->stores->isNotEmpty()) {
                $userStoreId = (int) $user->stores->first()->id;
            } elseif ($user->store_id !== null) {
                $userStoreId = (int) $user->store_id;
            }

            if ($userScope === Scope::BRANCH && $userStoreId !== (int) $context['store_id']) {
                return false;
            }
        }

        if ($requiredScope === Scope::SELF && isset($context['user_id'])) {
            if ($userScope === Scope::SELF && (int) $user->id !== (int) $context['user_id']) {
                return false;
            }
        }

        return true;
    }

    protected function resolveUserScope(User $user): Scope
    {
        $roleName = $user->role?->name;

        if ($user->role?->is_admin || in_array($roleName, ['Owner', 'Superadmin', 'Director'], true)) {
            return Scope::TENANT;
        }

        if (in_array($roleName, ['General Manager', 'Finance Manager', 'HR Manager'], true)) {
            return Scope::COMPANY;
        }

        if (in_array($roleName, ['Branch Manager', 'Store Manager', 'Supervisor', 'Kasir', 'Barista', 'Waiter', 'Staff'], true)) {
            return Scope::BRANCH;
        }

        return Scope::SELF;
    }
}
