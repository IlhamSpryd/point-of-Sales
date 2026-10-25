<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Brand;
use App\Models\User;

class BrandPolicy
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

    public function view(User $user, Brand $brand): bool
    {
        return (int) $user->tenant_id === (int) $brand->tenant_id;
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, Brand $brand): bool
    {
        return $this->canManage($user) && (int) $user->tenant_id === (int) $brand->tenant_id;
    }

    public function delete(User $user, Brand $brand): bool
    {
        return $this->canManage($user) && (int) $user->tenant_id === (int) $brand->tenant_id;
    }
}
