<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Setting;
use App\Models\User;

class SettingPolicy
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
