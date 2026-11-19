<?php

declare(strict_types=1);

namespace App\Policies;

use App\Modules\Organization\Models\Role;
use App\Modules\Organization\Models\User;

class RolePolicy
{
    private function isOwnerOrAdmin(User $user): bool
    {
        $role = $user->role;
        if (! $role instanceof Role) {
            return false;
        }

        return in_array($role->name, ['Owner', 'Admin'], true) || (bool) $role->is_admin;
    }

    private function isOwner(User $user): bool
    {
        $role = $user->role;
        if (! $role instanceof Role) {
            return false;
        }

        return $role->name === 'Owner' || (bool) $role->is_admin;
    }

    public function viewAny(User $user): bool
    {
        return $this->isOwnerOrAdmin($user);
    }

    public function view(User $user, Role $role): bool
    {
        return $this->isOwnerOrAdmin($user) && (int) $user->tenant_id === (int) $role->tenant_id;
    }

    public function create(User $user): bool
    {
        return $this->isOwnerOrAdmin($user);
    }

    public function update(User $user, Role $role): bool
    {
        if ((int) $user->tenant_id !== (int) $role->tenant_id) {
            return false;
        }

        // SoD Hard-block: Admin cannot edit the Owner role
        if ($user->role?->name === 'Admin' && $role->name === 'Owner') {
            return false;
        }

        return $this->isOwnerOrAdmin($user);
    }

    public function delete(User $user, Role $role): bool
    {
        if ((int) $user->tenant_id !== (int) $role->tenant_id) {
            return false;
        }

        // Owner role is immutable
        if ($role->name === 'Owner') {
            return false;
        }

        // SoD Hard-block: Admin cannot delete Owner or Admin roles
        if ($user->role?->name === 'Admin' && in_array($role->name, ['Owner', 'Admin'], true)) {
            return false;
        }

        return $this->isOwnerOrAdmin($user);
    }
}
