<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

class UserPolicy
{
    private function isOwner(User $user): bool
    {
        $role = $user->relationLoaded('role') ? $user->role : $user->role()->withoutGlobalScopes()->first();
        if (! $role instanceof Role) {
            return false;
        }

        return $role->name === 'Owner' || (bool) $role->is_admin;
    }

    public function viewAny(User $user): bool
    {
        return $this->isOwner($user);
    }

    public function view(User $user, User $model): bool
    {
        return $this->isOwner($user) && (int) $user->tenant_id === (int) $model->tenant_id;
    }

    public function create(User $user): bool
    {
        return $this->isOwner($user);
    }

    public function update(User $user, User $model): bool
    {
        return $this->isOwner($user) && (int) $user->tenant_id === (int) $model->tenant_id;
    }

    public function delete(User $user, User $model): bool
    {
        if ((int) $user->id === (int) $model->id) {
            return false;
        }

        return $this->isOwner($user) && (int) $user->tenant_id === (int) $model->tenant_id;
    }
}
