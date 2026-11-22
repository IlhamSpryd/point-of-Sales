<?php

declare(strict_types=1);

namespace App\Policies;

use App\Modules\Organization\Models\Role;
use App\Modules\Organization\Models\User;

class UserPolicy
{
    private function isOwnerOrAdmin(User $user): bool
    {
        $role = $user->role;
        if (! $role instanceof Role) {
            return false;
        }

        return in_array($role->name, ['Owner', 'Admin'], true) || (bool) $role->is_admin;
    }

    public function viewAny(User $user): bool
    {
        return $this->isOwnerOrAdmin($user);
    }

    public function view(User $user, User $model): bool
    {
        return $this->isOwnerOrAdmin($user) && (int) $user->tenant_id === (int) $model->tenant_id;
    }

    public function create(User $user): bool
    {
        return $this->isOwnerOrAdmin($user);
    }

    public function update(User $user, User $model): bool
    {
        if ((int) $user->tenant_id !== (int) $model->tenant_id) {
            return false;
        }

        // SoD Hard-block: Admin cannot edit an Owner account
        $targetRole = $model->role;
        if ($user->role?->name === 'Admin' && $targetRole?->name === 'Owner') {
            return false;
        }

        return $this->isOwnerOrAdmin($user);
    }

    public function delete(User $user, User $model): bool
    {
        if ((int) $user->id === (int) $model->id) {
            return false;
        }

        if ((int) $user->tenant_id !== (int) $model->tenant_id) {
            return false;
        }

        // SoD Hard-block: Admin cannot delete an Owner account
        $targetRole = $model->role;
        if ($user->role?->name === 'Admin' && $targetRole?->name === 'Owner') {
            return false;
        }

        return $this->isOwnerOrAdmin($user);
    }

    public function manageInvitations(User $user): bool
    {
        return $this->isOwnerOrAdmin($user);
    }
}
