<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Device;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DevicePolicy
{
    private function isOwner(User $user): bool
    {
        $role = $user->relationLoaded('role') ? $user->role : $user->role()->withoutGlobalScopes()->first();
        if (! $role instanceof Role) {
            return false;
        }

        return $role->name === 'Owner' || (bool) $role->is_admin;
    }

    private function isManager(User $user): bool
    {
        $role = $user->relationLoaded('role') ? $user->role : $user->role()->withoutGlobalScopes()->first();
        if (! $role instanceof Role) {
            return false;
        }

        return $role->name === 'Manager';
    }

    public function viewAny(User $user): bool
    {
        return $this->isOwner($user) || $this->isManager($user);
    }

    public function view(User $user, Device $device): bool
    {
        if ((int) $user->tenant_id !== (int) $device->tenant_id) {
            return false;
        }

        if ($this->isOwner($user)) {
            return true;
        }

        return DB::table('user_stores')
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->where('store_id', $device->store_id)
            ->exists();
    }

    public function create(User $user): bool
    {
        return $this->isOwner($user) || $this->isManager($user);
    }

    public function update(User $user, Device $device): bool
    {
        return ((int) $user->tenant_id === (int) $device->tenant_id) && ($this->isOwner($user) || $this->isManager($user));
    }

    public function delete(User $user, Device $device): bool
    {
        return ((int) $user->tenant_id === (int) $device->tenant_id) && $this->isOwner($user);
    }

    public function pair(User $user, Device $device): bool
    {
        return ((int) $user->tenant_id === (int) $device->tenant_id) && ($this->isOwner($user) || $this->isManager($user));
    }

    public function revoke(User $user, Device $device): bool
    {
        return ((int) $user->tenant_id === (int) $device->tenant_id) && ($this->isOwner($user) || $this->isManager($user));
    }
}
