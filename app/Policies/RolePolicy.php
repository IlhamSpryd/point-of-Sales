<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

class RolePolicy
{
    private function isOwner(User $user): bool
    {
        $role = $user->relationLoaded('role') ? $user->role : $user->role()->withoutGlobalScopes()->first();

        return $role?->name === 'Owner' || (bool) ($role?->is_admin ?? false);
    }

    public function viewAny(User $user): bool
    {
        return $this->isOwner($user);
    }

    public function view(User $user, Role $role): bool
    {
        return $this->isOwner($user) && (int) $user->tenant_id === (int) $role->tenant_id;
    }

    public function create(User $user): bool
    {
        return $this->isOwner($user);
    }

    public function update(User $user, Role $role): bool
    {
        return $this->isOwner($user) && (int) $user->tenant_id === (int) $role->tenant_id;
    }

    public function delete(User $user, Role $role): bool
    {
        return $this->isOwner($user) && (int) $user->tenant_id === (int) $role->tenant_id;
    }
}
