<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Purchasing\Models\SupplierInvoice;

class SupplierInvoicePolicy
{
    private function sameTenant(User $user, SupplierInvoice $invoice): bool
    {
        return (int) $user->tenant_id === (int) $invoice->tenant_id;
    }

    private function hasStoreAccess(User $user, SupplierInvoice $invoice): bool
    {
        if ($user->role?->name === 'Owner' || (bool) $user->role?->is_admin) {
            return true;
        }

        if (isset($user->store_id) && (int) $user->store_id === (int) $invoice->store_id) {
            return true;
        }

        return $user->stores()->where('stores.id', $invoice->store_id)->exists();
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('purchasing.invoice.view')
            || in_array($user->role?->name, ['Owner', 'Manager', 'Accounting', 'Admin'], true)
            || (bool) $user->role?->is_admin;
    }

    public function view(User $user, SupplierInvoice $invoice): bool
    {
        return $this->sameTenant($user, $invoice)
            && $this->hasStoreAccess($user, $invoice)
            && ($user->hasPermission('purchasing.invoice.view')
                || in_array($user->role?->name, ['Owner', 'Manager', 'Accounting', 'Admin'], true)
                || (bool) $user->role?->is_admin);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('purchasing.invoice.create')
            || in_array($user->role?->name, ['Owner', 'Manager', 'Accounting', 'Admin'], true)
            || (bool) $user->role?->is_admin;
    }

    public function update(User $user, SupplierInvoice $invoice): bool
    {
        if (! $this->sameTenant($user, $invoice) || ! $this->hasStoreAccess($user, $invoice)) {
            return false;
        }

        if (! $invoice->isDraft()) {
            return false;
        }

        return $user->hasPermission('purchasing.invoice.create')
            || in_array($user->role?->name, ['Owner', 'Manager', 'Accounting', 'Admin'], true)
            || (bool) $user->role?->is_admin;
    }

    public function match(User $user, SupplierInvoice $invoice): bool
    {
        if (! $this->sameTenant($user, $invoice) || ! $this->hasStoreAccess($user, $invoice)) {
            return false;
        }

        return $user->hasPermission('purchasing.invoice.match')
            || in_array($user->role?->name, ['Owner', 'Manager', 'Accounting', 'Admin'], true)
            || (bool) $user->role?->is_admin;
    }

    public function approveVariance(User $user, SupplierInvoice $invoice): bool
    {
        if (! $this->sameTenant($user, $invoice) || ! $this->hasStoreAccess($user, $invoice)) {
            return false;
        }

        if (! $invoice->isVarianceHold()) {
            return false;
        }

        return $user->hasPermission('purchasing.invoice.approve_variance')
            || in_array($user->role?->name, ['Owner', 'Accounting Manager', 'Admin'], true)
            || (bool) $user->role?->is_admin;
    }

    public function approvePayable(User $user, SupplierInvoice $invoice): bool
    {
        if (! $this->sameTenant($user, $invoice) || ! $this->hasStoreAccess($user, $invoice)) {
            return false;
        }

        if ($invoice->isApprovedPayable() || $invoice->isCancelled()) {
            return false;
        }

        return $user->hasPermission('purchasing.invoice.approve_payable')
            || in_array($user->role?->name, ['Owner', 'Manager', 'Accounting', 'Admin'], true)
            || (bool) $user->role?->is_admin;
    }

    public function cancel(User $user, SupplierInvoice $invoice): bool
    {
        if (! $this->sameTenant($user, $invoice) || ! $this->hasStoreAccess($user, $invoice)) {
            return false;
        }

        if ($invoice->isApprovedPayable()) {
            return false;
        }

        return $user->hasPermission('purchasing.invoice.cancel')
            || in_array($user->role?->name, ['Owner', 'Manager', 'Accounting', 'Admin'], true)
            || (bool) $user->role?->is_admin;
    }
}
