<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Table;
use App\Models\User;

class TablePolicy
{
    private function canManage(User $user): bool
    {
        $role = $user->role?->name;

        return in_array($role, ['Owner', 'Manager'], true);
    }

    private function hasStoreAccess(User $user, Table $table): bool
    {
        if ((int) $user->tenant_id !== (int) $table->tenant_id) {
            return false;
        }

        $role = $user->role?->name;
        if ($role === 'Owner' || (bool) ($user->role?->is_admin ?? false)) {
            return true;
        }

        if (isset($user->store_id) && $user->store_id !== null) {
            return (int) $user->store_id === (int) $table->store_id;
        }

        return $user->stores()->where('stores.id', $table->store_id)->exists();
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Table $table): bool
    {
        return $this->hasStoreAccess($user, $table);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, Table $table): bool
    {
        return $this->canManage($user) && $this->hasStoreAccess($user, $table);
    }

    public function delete(User $user, Table $table): bool
    {
        return $this->canManage($user) && $this->hasStoreAccess($user, $table);
    }
}
