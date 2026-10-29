<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Sales\Models\CustomerInvoice;

class CustomerInvoicePolicy
{
    private function sameTenant(User $user, CustomerInvoice $invoice): bool
    {
        return (int) $user->tenant_id === (int) $invoice->tenant_id;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('ar.view')
            || in_array($user->role?->name, ['Owner', 'Finance', 'Finance Manager', 'Manager', 'Admin'], true)
            || (bool) $user->role?->is_admin;
    }

    public function view(User $user, CustomerInvoice $invoice): bool
    {
        return $this->sameTenant($user, $invoice)
            && ($user->hasPermission('ar.view')
                || in_array($user->role?->name, ['Owner', 'Finance', 'Finance Manager', 'Manager', 'Admin'], true)
                || (bool) $user->role?->is_admin);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('ar.post_invoice')
            || in_array($user->role?->name, ['Owner', 'Finance', 'Finance Manager', 'Manager', 'Admin'], true)
            || (bool) $user->role?->is_admin;
    }

    public function post(User $user, CustomerInvoice $invoice): bool
    {
        return $this->sameTenant($user, $invoice)
            && ($user->hasPermission('ar.post_invoice')
                || in_array($user->role?->name, ['Owner', 'Finance', 'Finance Manager', 'Manager', 'Admin'], true)
                || (bool) $user->role?->is_admin);
    }

    public function allocatePayment(User $user, CustomerInvoice $invoice): bool
    {
        return $this->sameTenant($user, $invoice)
            && ($user->hasPermission('ar.allocate_payment')
                || in_array($user->role?->name, ['Owner', 'Finance', 'Finance Manager', 'Cashier', 'Manager', 'Admin'], true)
                || (bool) $user->role?->is_admin);
    }

    public function reverseAllocation(User $user): bool
    {
        return $user->hasPermission('ar.reverse_allocation')
            || in_array($user->role?->name, ['Owner', 'Finance Manager', 'Admin'], true)
            || (bool) $user->role?->is_admin;
    }

    public function void(User $user, CustomerInvoice $invoice): bool
    {
        return $this->sameTenant($user, $invoice)
            && ($user->hasPermission('ar.post_invoice')
                || in_array($user->role?->name, ['Owner', 'Finance Manager', 'Admin'], true)
                || (bool) $user->role?->is_admin);
    }

    public function export(User $user): bool
    {
        return $user->hasPermission('ar.export_statement')
            || in_array($user->role?->name, ['Owner', 'Finance', 'Finance Manager', 'Manager', 'Admin'], true)
            || (bool) $user->role?->is_admin;
    }
}
