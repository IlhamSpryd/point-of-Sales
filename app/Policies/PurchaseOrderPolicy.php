<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Setting;
use App\Models\User;
use App\Modules\Purchasing\Models\PurchaseOrder;

class PurchaseOrderPolicy
{
    private function sameTenant(User $user, PurchaseOrder $po): bool
    {
        return (int) $user->tenant_id === (int) $po->tenant_id;
    }

    private function hasStoreAccess(User $user, PurchaseOrder $po): bool
    {
        if ($user->role?->name === 'Owner' || (bool) $user->role?->is_admin) {
            return true;
        }

        if (isset($user->store_id) && (int) $user->store_id === (int) $po->store_id) {
            return true;
        }

        return $user->stores()->where('stores.id', $po->store_id)->exists();
    }

    private function isSoleActiveUser(User $user): bool
    {
        return User::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('is_active', true)
            ->count() === 1;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('purchasing.po.view')
            || in_array($user->role?->name, ['Owner', 'Manager', 'Inventory', 'Admin'], true)
            || (bool) $user->role?->is_admin;
    }

    public function view(User $user, PurchaseOrder $po): bool
    {
        return $this->sameTenant($user, $po)
            && $this->hasStoreAccess($user, $po)
            && ($user->hasPermission('purchasing.po.view')
                || in_array($user->role?->name, ['Owner', 'Manager', 'Inventory', 'Admin'], true)
                || (bool) $user->role?->is_admin);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('purchasing.po.create')
            || $user->hasPermission('purchasing.po.create_direct')
            || in_array($user->role?->name, ['Owner', 'Manager', 'Inventory', 'Admin'], true)
            || (bool) $user->role?->is_admin;
    }

    /**
     * Kontrol Otorisasi Direct PO (ADR-M5-02 & AT-05):
     * Pembuatan PO langsung tanpa PR hanya diizinkan jika:
     * 1. Pengaturan tenant `purchasing.po.require_pr` bernilai false/tidak aktif.
     * 2. Pengguna memegang izin eksplisit `purchasing.po.create_direct` (atau Owner/Super Admin).
     */
    public function createDirect(User $user): bool
    {
        // Cek pengaturan tenant
        $setting = Setting::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('key', 'purchasing.po.require_pr')
            ->first();

        $requirePr = $setting ? filter_var($setting->value, FILTER_VALIDATE_BOOLEAN) : false;

        if ($requirePr) {
            return false;
        }

        return $user->hasPermission('purchasing.po.create_direct')
            || $user->role?->name === 'Owner'
            || (bool) $user->role?->is_admin;
    }

    public function update(User $user, PurchaseOrder $po): bool
    {
        return $this->sameTenant($user, $po)
            && $this->hasStoreAccess($user, $po)
            && $po->canBeEdited()
            && ($user->hasPermission('purchasing.po.update')
                || in_array($user->role?->name, ['Owner', 'Manager', 'Inventory', 'Admin'], true)
                || (bool) $user->role?->is_admin);
    }

    public function approve(User $user, PurchaseOrder $po): bool
    {
        if (! $this->sameTenant($user, $po)) {
            return false;
        }

        if (! in_array($po->status->value, ['draft', 'pending_approval'], true)) {
            return false;
        }

        // Penegakan Pemisahan Tugas (Separation of Duties / SoD)
        if ((int) $user->id === (int) $po->created_by_user_id && ! $this->isSoleActiveUser($user)) {
            return false;
        }

        return $user->hasPermission('purchasing.po.approve')
            || in_array($user->role?->name, ['Owner', 'Manager', 'Admin'], true)
            || (bool) $user->role?->is_admin;
    }

    public function release(User $user, PurchaseOrder $po): bool
    {
        return $this->sameTenant($user, $po)
            && $this->hasStoreAccess($user, $po)
            && $po->canBeReleased()
            && ($user->hasPermission('purchasing.po.release')
                || in_array($user->role?->name, ['Owner', 'Manager', 'Inventory', 'Admin'], true)
                || (bool) $user->role?->is_admin);
    }

    public function amend(User $user, PurchaseOrder $po): bool
    {
        return $this->sameTenant($user, $po)
            && $this->hasStoreAccess($user, $po)
            && $po->canBeAmended()
            && ($user->hasPermission('purchasing.po.amend')
                || in_array($user->role?->name, ['Owner', 'Manager', 'Admin'], true)
                || (bool) $user->role?->is_admin);
    }

    public function cancel(User $user, PurchaseOrder $po): bool
    {
        return $this->sameTenant($user, $po)
            && $this->hasStoreAccess($user, $po)
            && $po->canBeCancelled()
            && ($user->hasPermission('purchasing.po.cancel')
                || in_array($user->role?->name, ['Owner', 'Manager', 'Admin'], true)
                || (bool) $user->role?->is_admin);
    }

    public function close(User $user, PurchaseOrder $po): bool
    {
        return $this->sameTenant($user, $po)
            && $this->hasStoreAccess($user, $po)
            && ! $po->isCancelled()
            && ($user->hasPermission('purchasing.po.close')
                || in_array($user->role?->name, ['Owner', 'Manager', 'Admin'], true)
                || (bool) $user->role?->is_admin);
    }

    public function viewPrices(User $user): bool
    {
        return $user->hasPermission('purchasing.po.view_prices')
            || in_array($user->role?->name, ['Owner', 'Manager', 'Admin'], true)
            || (bool) $user->role?->is_admin;
    }
}
