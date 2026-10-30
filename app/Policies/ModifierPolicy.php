<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Modifier;
use App\Models\User;

class ModifierPolicy
{
    private function sameTenant(User $user, Modifier $modifier): bool
    {
        return (int) $user->tenant_id === (int) $modifier->tenant_id;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('modifiers.view')
            || in_array($user->role?->name, ['Owner', 'Manager', 'Admin', 'Kasir', 'Kitchen', 'Barista', 'Waiter', 'Cook'], true)
            || (bool) $user->role?->is_admin;
    }

    public function view(User $user, Modifier $modifier): bool
    {
        return $this->sameTenant($user, $modifier)
            && ($user->hasPermission('modifiers.view')
                || in_array($user->role?->name, ['Owner', 'Manager', 'Admin', 'Kasir', 'Kitchen', 'Barista', 'Waiter', 'Cook'], true)
                || (bool) $user->role?->is_admin);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('modifiers.create')
            || in_array($user->role?->name, ['Owner', 'Manager', 'Admin'], true)
            || (bool) $user->role?->is_admin;
    }

    public function update(User $user, Modifier $modifier): bool
    {
        return $this->sameTenant($user, $modifier)
            && ($user->hasPermission('modifiers.update')
                || in_array($user->role?->name, ['Owner', 'Manager', 'Admin'], true)
                || (bool) $user->role?->is_admin);
    }

    public function delete(User $user, Modifier $modifier): bool
    {
        return $this->sameTenant($user, $modifier)
            && ($user->hasPermission('modifiers.delete')
                || in_array($user->role?->name, ['Owner', 'Admin'], true)
                || (bool) $user->role?->is_admin);
    }
}
