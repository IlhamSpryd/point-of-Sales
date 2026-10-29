<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Role;
use App\Models\User;
use App\Modules\Settings\Models\Store;
use Illuminate\Support\Facades\DB;

class StorePolicy
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

    public function view(User $user, Store $store): bool
    {
        if ((int) $user->tenant_id !== (int) $store->tenant_id) {
            return false;
        }

        if ($this->isOwner($user)) {
            return true;
        }

        return DB::table('user_stores')
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->where('store_id', $store->id)
            ->exists();
    }

    public function create(User $user): bool
    {
        return $this->isOwner($user);
    }

    public function update(User $user, Store $store): bool
    {
        return $this->isOwner($user) && (int) $user->tenant_id === (int) $store->tenant_id;
    }

    public function delete(User $user, Store $store): bool
    {
        // Cabang utama (is_initial) tidak boleh dihapus
        if ($store->is_initial) {
            return false;
        }

        return $this->isOwner($user) && (int) $user->tenant_id === (int) $store->tenant_id;
    }

    public function switch(User $user, Store $store): bool
    {
        if ((int) $user->tenant_id !== (int) $store->tenant_id) {
            return false;
        }

        if ($this->isOwner($user)) {
            return true;
        }

        return DB::table('user_stores')
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->where('store_id', $store->id)
            ->exists();
    }
}
