<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Purchasing\Models\Supplier;

class SupplierPolicy
{
    private function sameTenant(User $user, Supplier $supplier): bool
    {
        return (int) $user->tenant_id === (int) $supplier->tenant_id;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('purchasing.supplier.view')
            || in_array($user->role?->name, ['Owner', 'Manager', 'Admin'], true)
            || (bool) $user->role?->is_admin;
    }

    public function view(User $user, Supplier $supplier): bool
    {
        return $this->sameTenant($user, $supplier)
            && ($user->hasPermission('purchasing.supplier.view')
                || in_array($user->role?->name, ['Owner', 'Manager', 'Admin'], true)
                || (bool) $user->role?->is_admin);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('purchasing.supplier.create')
            || in_array($user->role?->name, ['Owner', 'Manager', 'Admin'], true)
            || (bool) $user->role?->is_admin;
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $this->sameTenant($user, $supplier)
            && ($user->hasPermission('purchasing.supplier.update')
                || in_array($user->role?->name, ['Owner', 'Manager', 'Admin'], true)
                || (bool) $user->role?->is_admin);
    }

    public function delete(User $user, Supplier $supplier): bool
    {
        return $this->sameTenant($user, $supplier)
            && ($user->hasPermission('purchasing.supplier.archive')
                || in_array($user->role?->name, ['Owner', 'Admin'], true)
                || (bool) $user->role?->is_admin);
    }

    public function archive(User $user, Supplier $supplier): bool
    {
        return $this->sameTenant($user, $supplier)
            && ($user->hasPermission('purchasing.supplier.archive')
                || in_array($user->role?->name, ['Owner', 'Admin'], true)
                || (bool) $user->role?->is_admin);
    }

    public function manageCatalog(User $user, Supplier $supplier): bool
    {
        return $this->sameTenant($user, $supplier)
            && ($user->hasPermission('purchasing.supplier.manage_catalog')
                || in_array($user->role?->name, ['Owner', 'Manager', 'Admin'], true)
                || (bool) $user->role?->is_admin);
    }
}
