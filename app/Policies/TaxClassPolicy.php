<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TaxClass;
use App\Models\User;

class TaxClassPolicy
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

    public function view(User $user, TaxClass $taxClass): bool
    {
        return (int) $user->tenant_id === (int) $taxClass->tenant_id;
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, TaxClass $taxClass): bool
    {
        return $this->canManage($user) && (int) $user->tenant_id === (int) $taxClass->tenant_id;
    }

    public function delete(User $user, TaxClass $taxClass): bool
    {
        return $this->canManage($user) && (int) $user->tenant_id === (int) $taxClass->tenant_id;
    }
}
