<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Purchasing\Models\GoodsReceivedNote;

class GrnPolicy
{
    private function sameTenant(User $user, GoodsReceivedNote $grn): bool
    {
        return (int) $user->tenant_id === (int) $grn->tenant_id;
    }

    private function hasStoreAccess(User $user, GoodsReceivedNote $grn): bool
    {
        if ($user->role?->name === 'Owner' || (bool) $user->role?->is_admin) {
            return true;
        }

        if (isset($user->store_id) && (int) $user->store_id === (int) $grn->store_id) {
            return true;
        }

        return $user->stores()->where('stores.id', $grn->store_id)->exists();
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('purchasing.grn.view')
            || in_array($user->role?->name, ['Owner', 'Manager', 'Inventory', 'Admin'], true)
            || (bool) $user->role?->is_admin;
    }

    public function view(User $user, GoodsReceivedNote $grn): bool
    {
        return $this->sameTenant($user, $grn)
            && $this->hasStoreAccess($user, $grn)
            && ($user->hasPermission('purchasing.grn.view')
                || in_array($user->role?->name, ['Owner', 'Manager', 'Inventory', 'Admin'], true)
                || (bool) $user->role?->is_admin);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('purchasing.grn.create')
            || in_array($user->role?->name, ['Owner', 'Manager', 'Inventory', 'Admin'], true)
            || (bool) $user->role?->is_admin;
    }

    public function update(User $user, GoodsReceivedNote $grn): bool
    {
        if (! $this->sameTenant($user, $grn) || ! $this->hasStoreAccess($user, $grn)) {
            return false;
        }

        if (! $grn->isDraft()) {
            return false;
        }

        return $user->hasPermission('purchasing.grn.create')
            || in_array($user->role?->name, ['Owner', 'Manager', 'Inventory', 'Admin'], true)
            || (bool) $user->role?->is_admin;
    }

    public function post(User $user, GoodsReceivedNote $grn): bool
    {
        if (! $this->sameTenant($user, $grn) || ! $this->hasStoreAccess($user, $grn)) {
            return false;
        }

        if (! $grn->isDraft()) {
            return false;
        }

        return $user->hasPermission('purchasing.grn.post')
            || in_array($user->role?->name, ['Owner', 'Manager', 'Inventory', 'Admin'], true)
            || (bool) $user->role?->is_admin;
    }

    public function reverse(User $user, GoodsReceivedNote $grn): bool
    {
        if (! $this->sameTenant($user, $grn) || ! $this->hasStoreAccess($user, $grn)) {
            return false;
        }

        if (! $grn->isPosted()) {
            return false;
        }

        return $user->hasPermission('purchasing.grn.reverse')
            || in_array($user->role?->name, ['Owner', 'Manager', 'Admin'], true)
            || (bool) $user->role?->is_admin;
    }

    public function cancel(User $user, GoodsReceivedNote $grn): bool
    {
        if (! $this->sameTenant($user, $grn) || ! $this->hasStoreAccess($user, $grn)) {
            return false;
        }

        if (! $grn->isDraft()) {
            return false;
        }

        return $user->hasPermission('purchasing.grn.cancel')
            || in_array($user->role?->name, ['Owner', 'Manager', 'Inventory', 'Admin'], true)
            || (bool) $user->role?->is_admin;
    }

    /**
     * Kontrol Blind Receiving: staf gudang tanpa izin view_prices
     * tidak boleh melihat harga kontrak PO/GRN.
     */
    public function viewPrices(User $user): bool
    {
        return $user->hasPermission('purchasing.po.view_prices')
            || in_array($user->role?->name, ['Owner', 'Manager', 'Admin'], true)
            || (bool) $user->role?->is_admin;
    }
}
