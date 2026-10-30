<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\OrderReturn;
use App\Models\User;

class OrderReturnPolicy
{
    private function sameTenant(User $user, OrderReturn $orderReturn): bool
    {
        return (int) $user->tenant_id === (int) $orderReturn->tenant_id;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('returns.view')
            || in_array($user->role?->name, ['Owner', 'Manager', 'Admin', 'Kasir', 'Supervisor', 'Finance'], true)
            || (bool) $user->role?->is_admin;
    }

    public function view(User $user, OrderReturn $orderReturn): bool
    {
        return $this->sameTenant($user, $orderReturn)
            && ($user->hasPermission('returns.view')
                || in_array($user->role?->name, ['Owner', 'Manager', 'Admin', 'Kasir', 'Supervisor', 'Finance'], true)
                || (bool) $user->role?->is_admin);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('returns.create')
            || in_array($user->role?->name, ['Owner', 'Manager', 'Admin', 'Kasir', 'Supervisor'], true)
            || (bool) $user->role?->is_admin;
    }

    public function update(User $user, OrderReturn $orderReturn): bool
    {
        return $this->sameTenant($user, $orderReturn)
            && ($user->hasPermission('returns.update')
                || in_array($user->role?->name, ['Owner', 'Manager', 'Admin', 'Supervisor'], true)
                || (bool) $user->role?->is_admin);
    }

    public function delete(User $user, OrderReturn $orderReturn): bool
    {
        return $this->sameTenant($user, $orderReturn)
            && ($user->hasPermission('returns.delete')
                || in_array($user->role?->name, ['Owner', 'Admin'], true)
                || (bool) $user->role?->is_admin);
    }

    public function approve(User $user, OrderReturn $orderReturn): bool
    {
        return $this->sameTenant($user, $orderReturn)
            && ($user->hasPermission('returns.approve')
                || in_array($user->role?->name, ['Owner', 'Manager', 'Admin', 'Supervisor'], true)
                || (bool) $user->role?->is_admin);
    }

    public function refund(User $user, OrderReturn $orderReturn): bool
    {
        return $this->sameTenant($user, $orderReturn)
            && ($user->hasPermission('returns.refund')
                || in_array($user->role?->name, ['Owner', 'Manager', 'Admin', 'Finance', 'Kasir'], true)
                || (bool) $user->role?->is_admin);
    }
}
