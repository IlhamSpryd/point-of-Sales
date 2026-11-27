<?php

declare(strict_types=1);

namespace App\Policies;

use App\Modules\Organization\Models\Role;
use App\Modules\Organization\Models\Setting;
use App\Modules\Organization\Models\User;

class SettingPolicy
{
    private function isOwnerOrAdmin(User $user): bool
    {
        // @tenant-bypass: auth_lookup
        $role = $user->relationLoaded('role') ? $user->role : $user->role()->withoutGlobalScopes()->first();
        if (! $role instanceof Role) {
            return false;
        }

        return in_array($role->name, ['Owner', 'Admin'], true) || (bool) $role->is_admin;
    }

    public function viewAny(User $user): bool
    {
        return $this->isOwnerOrAdmin($user);
    }

    public function view(User $user, Setting $setting): bool
    {
        return $this->isOwnerOrAdmin($user) && (int) $user->tenant_id === (int) $setting->tenant_id;
    }

    public function create(User $user): bool
    {
        return $this->isOwnerOrAdmin($user);
    }

    public function update(User $user, Setting $setting): bool
    {
        return $this->isOwnerOrAdmin($user) && (int) $user->tenant_id === (int) $setting->tenant_id;
    }

    public function delete(User $user, Setting $setting): bool
    {
        return $this->isOwnerOrAdmin($user) && (int) $user->tenant_id === (int) $setting->tenant_id;
    }
}
