<?php

declare(strict_types=1);

namespace App\Services\Iam;

use App\Models\Role;
use App\Models\User;
use App\Models\UserStoreRole;
use App\Services\Context\TenantContext;
use Illuminate\Support\Collection;

/**
 * Deterministic IAM Engine (Section 2 of Blueprint)
 *
 * Implements strict 9-stage evaluation order:
 * Explicit Deny > Scope Validation > SoD Restrictions > Temporal Validity >
 * Approval Requirements > ABAC Quantitative Constraints > Explicit Allow > Default Deny.
 *
 * Anti-Privilege Escalation:
 * Enforces domain-specific limits (e.g. Sales checkout discount reads ONLY sales/pos roles,
 * never evaluating Inventory Supervisor adjustment limits via MAX()).
 */
class DeterministicAuthorizationEngine
{
    public function __construct(
        protected TenantContext $tenantContext
    ) {}

    /**
     * Evaluates permission for an action and optional resource context.
     *
     * @param array{
     *     domain?: string,
     *     store_id?: int,
     *     operational_unit_id?: int,
     *     requested_amount?: float,
     *     requested_percentage?: float,
     *     target_user_id?: int,
     *     is_maker?: bool
     * } $context
     */
    public function evaluate(User $user, string $permission, array $context = []): AuthorizationResult
    {
        // 1. Identity & Tenant Validation (Fail-closed)
        $currentTenantId = $this->tenantContext->getTenantId() ?? $user->tenant_id;
        if (! $currentTenantId || (int) $user->tenant_id !== (int) $currentTenantId) {
            return AuthorizationResult::deny(
                AuthorizationResult::CODE_TENANT_MISMATCH,
                'Cross-tenant identity violation. User does not belong to active tenant.'
            );
        }

        $activeStoreId = $context['store_id'] ?? $this->tenantContext->getStoreId() ?? $user->store_id;

        // 2. Resolve Active Roles for this store scope
        $roleAssignments = $this->resolveAssignments($user, $activeStoreId ? (int) $activeStoreId : null);

        // 3. Step 1: Explicit Deny Check
        foreach ($roleAssignments as $assignment) {
            $role = $assignment['role'];
            $permissions = (array) ($role->permissions ?? []);

            // Check explicit deny marker: DENY:permission or DENY:*
            if (in_array("DENY:{$permission}", $permissions, true) || in_array('DENY:*', $permissions, true)) {
                return AuthorizationResult::deny(
                    AuthorizationResult::CODE_EXPLICIT_DENY,
                    "Action '{$permission}' is explicitly denied by role policy."
                );
            }
        }

        // 4. Step 2: Context Scope Mismatch Check
        if ($activeStoreId !== null && ! $this->isOwnerOrAdmin($user)) {
            $hasStoreScope = $roleAssignments->contains(fn ($a) => (int) $a['store_id'] === (int) $activeStoreId);
            if (! $hasStoreScope) {
                return AuthorizationResult::deny(
                    AuthorizationResult::CODE_SCOPE_MISMATCH,
                    "User roles are not scoped to store ID {$activeStoreId}."
                );
            }
        }

        // 5. Step 3: Segregation of Duties (SoD) Check
        // Maker cannot be Checker (anti-self-approval)
        if (! empty($context['is_maker']) && ! empty($context['target_user_id'])) {
            if ((int) $user->id === (int) $context['target_user_id']) {
                return AuthorizationResult::deny(
                    AuthorizationResult::CODE_SOD_VIOLATION,
                    'Segregation of duties violation: Self-approval/maker-checker conflict.'
                );
            }
        }

        // 6. Step 4: Temporal Validity Check
        $validAssignments = $roleAssignments->filter(function ($a) {
            if (! $a['is_active']) {
                return false;
            }
            $today = now()->toDateString();
            if ($a['valid_from'] && $a['valid_from'] > $today) {
                return false;
            }
            if ($a['valid_to'] && $a['valid_to'] < $today) {
                return false;
            }

            return true;
        });

        if ($validAssignments->isEmpty() && ! $this->isOwnerOrAdmin($user)) {
            return AuthorizationResult::deny(
                AuthorizationResult::CODE_TEMPORAL_EXPIRED,
                'Role assignments are either inactive or temporally expired.'
            );
        }

        // 7. Step 5 & 6: Approval Requirements & ABAC Quantitative Constraints
        // Anti-MAX() rule: Determine domain from action/context
        $domain = $context['domain'] ?? $this->inferDomain($permission);

        if ($domain === 'sales' && (isset($context['requested_percentage']) || isset($context['requested_amount']))) {
            $salesResult = $this->evaluateSalesDiscountLimit($validAssignments, $user, $context);
            if (! $salesResult->isAllowed) {
                return $salesResult;
            }
        }

        // 8. Step 7: Explicit Permission Grant
        if ($this->isOwnerOrAdmin($user)) {
            return AuthorizationResult::allow(AuthorizationResult::CODE_ALLOWED, 'Owner/Admin explicit bypass grant.');
        }

        foreach ($validAssignments as $a) {
            /** @var Role $role */
            $role = $a['role'];
            if ($role->hasPermission($permission)) {
                return AuthorizationResult::allow(
                    AuthorizationResult::CODE_ALLOWED,
                    "Permission granted via role '{$role->name}'."
                );
            }
        }

        // 9. Step 8: Default Deny (Fail-closed)
        return AuthorizationResult::deny(
            AuthorizationResult::CODE_DEFAULT_DENY,
            "Access denied: No matching permission grant found for '{$permission}'."
        );
    }

    /**
     * Evaluates autonomous discount limits for POS sales checkout.
     * Crucial: reads ONLY roles that belong to the sales/pos domain.
     *
     * @param Collection<int, array{role: Role, store_id: ?int, valid_from: ?string, valid_to: ?string, is_active: bool}> $assignments
     */
    protected function evaluateSalesDiscountLimit(Collection $assignments, User $user, array $context): AuthorizationResult
    {
        if ($this->isOwnerOrAdmin($user)) {
            return AuthorizationResult::allow();
        }

        // Relevant roles for sales checkout discounts:
        // Cashier, Supervisor, Manager, Waiter
        $salesRoles = $assignments->filter(function ($a) {
            $name = strtolower($a['role']->name);

            return in_array($name, ['kasir', 'cashier', 'supervisor', 'manager', 'waiter', 'barista'], true);
        });

        // Determine cashier autonomous limit
        $maxAutonomousDiscountPercent = 5.0; // Default cashier discount limit (5%)
        $maxAutonomousDiscountAmount = 10000.0; // Default cashier discount limit (Rp 10.000)

        // If user only holds cashier role, limit is 5%
        // If user holds manager/supervisor role for sales, limit is higher
        $hasSalesSupervisor = $salesRoles->contains(fn ($a) => in_array(strtolower($a['role']->name), ['supervisor', 'manager'], true));
        if ($hasSalesSupervisor) {
            $maxAutonomousDiscountPercent = 20.0;
            $maxAutonomousDiscountAmount = 50000.0;
        }

        $requestedPercent = (float) ($context['requested_percentage'] ?? 0.0);
        $requestedAmount = (float) ($context['requested_amount'] ?? 0.0);

        if ($requestedPercent > $maxAutonomousDiscountPercent || $requestedAmount > $maxAutonomousDiscountAmount) {
            return AuthorizationResult::requiresApproval(
                "Requested discount exceeds autonomous limit ({$maxAutonomousDiscountPercent}% / Rp ".number_format($maxAutonomousDiscountAmount, 0).'). Supervisor approval required.',
                $maxAutonomousDiscountPercent,
                ['max_percent' => $maxAutonomousDiscountPercent, 'max_amount' => $maxAutonomousDiscountAmount]
            );
        }

        return AuthorizationResult::allow();
    }

    /**
     * Resolves role assignments for user and store scope.
     *
     * @return Collection<int, array{role: Role, store_id: ?int, valid_from: ?string, valid_to: ?string, is_active: bool}>
     */
    protected function resolveAssignments(User $user, ?int $storeId): Collection
    {
        $assignments = collect();

        // 1. Check normalized user_store_roles table
        $hasStoreRoles = UserStoreRole::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->exists();

        if ($hasStoreRoles) {
            $records = UserStoreRole::withoutGlobalScopes()
                ->where('tenant_id', $user->tenant_id)
                ->where('user_id', $user->id)
                ->when($storeId !== null, fn ($q) => $q->where('store_id', $storeId))
                ->with('role')
                ->get();

            foreach ($records as $record) {
                if ($record->role) {
                    $assignments->push([
                        'role' => $record->role,
                        'store_id' => (int) $record->store_id,
                        'valid_from' => $record->valid_from ? \Carbon\Carbon::parse($record->valid_from)->toDateString() : null,
                        'valid_to' => $record->valid_to ? \Carbon\Carbon::parse($record->valid_to)->toDateString() : null,
                        'is_active' => (bool) $record->is_active,
                    ]);
                }
            }

            return $assignments;
        }

        // 2. Include primary user role for backward compatibility ONLY IF user belongs to the store
        if ($user->role) {
            $isAssigned = false;
            if ($storeId === null || $this->isOwnerOrAdmin($user)) {
                $isAssigned = true;
            } elseif ((int) $user->store_id === (int) $storeId) {
                $isAssigned = true;
            } else {
                $isAssigned = \Illuminate\Support\Facades\DB::table('user_stores')
                    ->where('tenant_id', $user->tenant_id)
                    ->where('user_id', $user->id)
                    ->where('store_id', $storeId)
                    ->exists();
            }

            if ($isAssigned) {
                $assignments->push([
                    'role' => $user->role,
                    'store_id' => $storeId,
                    'valid_from' => null,
                    'valid_to' => null,
                    'is_active' => true,
                ]);
            }
        }

        return $assignments;
    }

    protected function isOwnerOrAdmin(User $user): bool
    {
        $roleName = $user->role?->name;

        return $roleName === 'Owner' || (bool) ($user->role->is_admin ?? false);
    }

    protected function inferDomain(string $permission): string
    {
        if (str_starts_with($permission, 'pos.') || str_starts_with($permission, 'orders.') || str_starts_with($permission, 'sales.')) {
            return 'sales';
        }
        if (str_starts_with($permission, 'inventory.') || str_starts_with($permission, 'stock_opname.') || str_starts_with($permission, 'warehouse.')) {
            return 'inventory';
        }
        if (str_starts_with($permission, 'finance.') || str_starts_with($permission, 'accounting.')) {
            return 'finance';
        }

        return 'general';
    }
}
