<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Customers\Models\Customer;

class CustomerPolicy
{
    private function sameTenant(User $user, Customer $customer): bool
    {
        return (int) $user->tenant_id === (int) $customer->tenant_id;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('customers.view')
            || in_array($user->role?->name, ['Owner', 'Manager', 'Admin'], true)
            || (bool) $user->role?->is_admin;
    }

    public function view(User $user, Customer $customer): bool
    {
        return $this->sameTenant($user, $customer)
            && ($user->hasPermission('customers.view')
                || in_array($user->role?->name, ['Owner', 'Manager', 'Admin'], true)
                || (bool) $user->role?->is_admin);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('customers.create')
            || in_array($user->role?->name, ['Owner', 'Manager', 'Admin'], true)
            || (bool) $user->role?->is_admin;
    }

    public function update(User $user, Customer $customer): bool
    {
        return $this->sameTenant($user, $customer)
            && ($user->hasPermission('customers.update')
                || in_array($user->role?->name, ['Owner', 'Manager', 'Admin'], true)
                || (bool) $user->role?->is_admin);
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $this->sameTenant($user, $customer)
            && ($user->hasPermission('customers.delete')
                || in_array($user->role?->name, ['Owner', 'Admin'], true)
                || (bool) $user->role?->is_admin);
    }

    public function merge(User $user, Customer $customer): bool
    {
        return $this->sameTenant($user, $customer)
            && ($user->hasPermission('customers.merge')
                || in_array($user->role?->name, ['Owner', 'Manager', 'Admin'], true)
                || (bool) $user->role?->is_admin);
    }

    public function manageGroups(User $user): bool
    {
        return $user->hasPermission('customers.manage_groups')
            || in_array($user->role?->name, ['Owner', 'Manager', 'Admin'], true)
            || (bool) $user->role?->is_admin;
    }

    public function exportPii(User $user): bool
    {
        return $user->hasPermission('customers.export_pii')
            || in_array($user->role?->name, ['Owner', 'Admin'], true)
            || (bool) $user->role?->is_admin;
    }

    public function viewCredit(User $user, Customer $customer): bool
    {
        return $this->sameTenant($user, $customer)
            && ($user->hasPermission('credit.view')
                || in_array($user->role?->name, ['Owner', 'Manager', 'Finance', 'Admin'], true)
                || (bool) $user->role?->is_admin);
    }

    public function manageCreditLimit(User $user, Customer $customer): bool
    {
        return $this->sameTenant($user, $customer)
            && ($user->hasPermission('credit.manage_limit')
                || in_array($user->role?->name, ['Owner', 'Manager', 'Finance'], true)
                || (bool) $user->role?->is_admin);
    }

    public function manageCreditStatus(User $user, Customer $customer): bool
    {
        return $this->sameTenant($user, $customer)
            && ($user->hasPermission('credit.hold_release')
                || in_array($user->role?->name, ['Owner', 'Manager', 'Finance'], true)
                || (bool) $user->role?->is_admin);
    }

    public function overrideCredit(User $user, ?Customer $customer = null): bool
    {
        if ($customer !== null && ! $this->sameTenant($user, $customer)) {
            return false;
        }

        return $user->hasPermission('credit.override')
            || in_array($user->role?->name, ['Owner', 'Finance Manager'], true)
            || (bool) $user->role?->is_admin;
    }
}
