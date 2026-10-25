<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Purchasing\Models\PurchaseReturn;

class PurchaseReturnPolicy
{
    private function sameTenant(User $user, PurchaseReturn $return): bool
    {
        return (int) $user->tenant_id === (int) $return->tenant_id;
    }

    private function hasStoreAccess(User $user, PurchaseReturn $return): bool
    {
        if ($user->role?->name === 'Owner' || (bool) $user->role?->is_admin) {
            return true;
        }

        if (isset($user->store_id) && (int) $user->store_id === (int) $return->store_id) {
            return true;
        }

        return $user->stores()->where('stores.id', $return->store_id)->exists();
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('purchasing.return.view')
            || in_array($user->role?->name, ['Owner', 'Manager', 'Accounting', 'Admin'], true)
            || (bool) $user->role?->is_admin;
    }

    public function view(User $user, PurchaseReturn $return): bool
    {
        return $this->sameTenant($user, $return)
            && $this->hasStoreAccess($user, $return)
            && ($user->hasPermission('purchasing.return.view')
                || in_array($user->role?->name, ['Owner', 'Manager', 'Accounting', 'Admin'], true)
                || (bool) $user->role?->is_admin);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('purchasing.return.create')
            || in_array($user->role?->name, ['Owner', 'Manager', 'Accounting', 'Admin'], true)
            || (bool) $user->role?->is_admin;
    }

    public function approve(User $user, PurchaseReturn $return): bool
    {
        return $this->sameTenant($user, $return)
            && $this->hasStoreAccess($user, $return)
            && ($user->hasPermission('purchasing.return.approve')
                || in_array($user->role?->name, ['Owner', 'Manager', 'Admin'], true)
                || (bool) $user->role?->is_admin);
    }

    public function post(User $user, PurchaseReturn $return): bool
    {
        return $this->sameTenant($user, $return)
            && $this->hasStoreAccess($user, $return)
            && ($user->hasPermission('purchasing.return.post')
                || in_array($user->role?->name, ['Owner', 'Manager', 'Admin'], true)
                || (bool) $user->role?->is_admin);
    }

    public function manageDebitNote(User $user): bool
    {
        return $user->hasPermission('purchasing.debit_note.manage')
            || in_array($user->role?->name, ['Owner', 'Manager', 'Accounting', 'Admin'], true)
            || (bool) $user->role?->is_admin;
    }
}
