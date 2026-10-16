<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;

class SettingPolicy
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

    public function view(User $user, Setting $setting): bool
    {
        return $this->isOwner($user) && (int) $user->tenant_id === (int) $setting->tenant_id;
    }

    public function create(User $user): bool
    {
        return $this->isOwner($user);
    }

    public function update(User $user, Setting $setting): bool
    {
        return $this->isOwner($user) && (int) $user->tenant_id === (int) $setting->tenant_id;
    }

    public function delete(User $user, Setting $setting): bool
    {
        return $this->isOwner($user) && (int) $user->tenant_id === (int) $setting->tenant_id;
    }
}
