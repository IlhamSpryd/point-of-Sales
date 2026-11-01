<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Customers\Models\StoreCreditAccount;

class StoreCreditPolicy
{
    private function hasRolePermission(User $user, string $permission): bool
    {
        $role = $user->relationLoaded('role') ? $user->role : $user->role()->withoutGlobalScopes()->first();
        if (! $role) {
            return false;
        }

        if ((bool) $role->is_admin || strtolower($role->name ?? '') === 'owner' || strtolower($role->name ?? '') === 'manager') {
            return true;
        }

        return $user->hasPermission($permission);
    }

    public function viewAny(User $user): bool
    {
        return $this->hasRolePermission($user, 'store_credits.view') || $this->hasRolePermission($user, 'customers.view');
    }

    public function view(User $user, StoreCreditAccount $account): bool
    {
        if ((int) $user->tenant_id !== (int) $account->tenant_id) {
            return false;
        }

        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->hasRolePermission($user, 'store_credits.issue');
    }
}
