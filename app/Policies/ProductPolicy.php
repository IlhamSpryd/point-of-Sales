<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy
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

    public function view(User $user, Product $product): bool
    {
        return (int) $user->tenant_id === (int) $product->tenant_id;
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, ?Product $product = null): bool
    {
        if ($product === null) {
            return $this->canManage($user);
        }

        return $this->canManage($user) && (int) $user->tenant_id === (int) $product->tenant_id;
    }

    public function delete(User $user, ?Product $product = null): bool
    {
        if ($product === null) {
            return $this->canManage($user);
        }

        return $this->canManage($user) && (int) $user->tenant_id === (int) $product->tenant_id;
    }
}
