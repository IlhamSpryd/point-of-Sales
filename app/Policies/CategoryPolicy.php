<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Category;
use App\Models\User;

class CategoryPolicy
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

    public function view(User $user, Category $category): bool
    {
        return (int) $user->tenant_id === (int) $category->tenant_id;
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, Category $category): bool
    {
        return $this->canManage($user) && (int) $user->tenant_id === (int) $category->tenant_id;
    }

    public function delete(User $user, Category $category): bool
    {
        return $this->canManage($user) && (int) $user->tenant_id === (int) $category->tenant_id;
    }
}
