<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\UnitOfMeasure;
use App\Models\User;

class UnitOfMeasurePolicy
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

    public function view(User $user, UnitOfMeasure $uom): bool
    {
        return (int) $user->tenant_id === (int) $uom->tenant_id;
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, UnitOfMeasure $uom): bool
    {
        return $this->canManage($user) && (int) $user->tenant_id === (int) $uom->tenant_id;
    }

    public function delete(User $user, UnitOfMeasure $uom): bool
    {
        return $this->canManage($user) && (int) $user->tenant_id === (int) $uom->tenant_id;
    }
}
