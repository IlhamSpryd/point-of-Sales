<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\StoreProductAssortment;
use App\Models\User;

class StoreProductAssortmentPolicy
{
    private function canManage(User $user): bool
    {
        $role = $user->role?->name;

        return in_array($role, ['Owner', 'Manager', 'Inventory'], true);
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, StoreProductAssortment $assortment): bool
    {
        return (int) $user->tenant_id === (int) $assortment->tenant_id;
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, ?StoreProductAssortment $assortment = null): bool
    {
        if ($assortment === null) {
            return $this->canManage($user);
        }

        return $this->canManage($user) && (int) $user->tenant_id === (int) $assortment->tenant_id;
    }

    public function delete(User $user, ?StoreProductAssortment $assortment = null): bool
    {
        if ($assortment === null) {
            return $this->canManage($user);
        }

        return $this->canManage($user) && (int) $user->tenant_id === (int) $assortment->tenant_id;
    }
}
