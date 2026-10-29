<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Sales\Models\SalesOrder;

class SalesOrderPolicy
{
    private function sameTenant(User $user, SalesOrder $salesOrder): bool
    {
        return (int) $user->tenant_id === (int) $salesOrder->tenant_id;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('sales_orders.view')
            || in_array($user->role?->name, ['Owner', 'Manager', 'Admin', 'Sales'], true)
            || (bool) $user->role?->is_admin;
    }

    public function view(User $user, SalesOrder $salesOrder): bool
    {
        return $this->sameTenant($user, $salesOrder)
            && ($user->hasPermission('sales_orders.view')
                || in_array($user->role?->name, ['Owner', 'Manager', 'Admin', 'Sales'], true)
                || (bool) $user->role?->is_admin);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('sales_orders.create')
            || in_array($user->role?->name, ['Owner', 'Manager', 'Admin', 'Sales'], true)
            || (bool) $user->role?->is_admin;
    }

    public function update(User $user, SalesOrder $salesOrder): bool
    {
        return $this->sameTenant($user, $salesOrder)
            && ($user->hasPermission('sales_orders.update')
                || in_array($user->role?->name, ['Owner', 'Manager', 'Admin', 'Sales'], true)
                || (bool) $user->role?->is_admin);
    }

    public function confirm(User $user, SalesOrder $salesOrder): bool
    {
        return $this->sameTenant($user, $salesOrder)
            && ($user->hasPermission('sales_orders.confirm')
                || in_array($user->role?->name, ['Owner', 'Manager', 'Admin'], true)
                || (bool) $user->role?->is_admin);
    }

    public function fulfill(User $user, SalesOrder $salesOrder): bool
    {
        return $this->sameTenant($user, $salesOrder)
            && ($user->hasPermission('sales_orders.fulfill')
                || in_array($user->role?->name, ['Owner', 'Manager', 'Admin', 'Warehouse'], true)
                || (bool) $user->role?->is_admin);
    }

    public function cancel(User $user, SalesOrder $salesOrder): bool
    {
        return $this->sameTenant($user, $salesOrder)
            && ($user->hasPermission('sales_orders.cancel')
                || in_array($user->role?->name, ['Owner', 'Manager', 'Admin'], true)
                || (bool) $user->role?->is_admin);
    }

    public function delete(User $user, SalesOrder $salesOrder): bool
    {
        return $this->sameTenant($user, $salesOrder)
            && ($user->hasPermission('sales_orders.delete')
                || in_array($user->role?->name, ['Owner', 'Admin'], true)
                || (bool) $user->role?->is_admin);
    }
}
