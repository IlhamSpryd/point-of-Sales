<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Purchasing\Models\PurchaseRequest;

class PurchaseRequestPolicy
{
    private function sameTenant(User $user, PurchaseRequest $pr): bool
    {
        return (int) $user->tenant_id === (int) $pr->tenant_id;
    }

    private function hasStoreAccess(User $user, PurchaseRequest $pr): bool
    {
        if ($user->role?->name === 'Owner' || (bool) $user->role?->is_admin) {
            return true;
        }

        if (isset($user->store_id) && (int) $user->store_id === (int) $pr->store_id) {
            return true;
        }

        return $user->stores()->where('stores.id', $pr->store_id)->exists();
    }

    /**
     * Memeriksa apakah user adalah satu-satunya user aktif di tenant tersebut (fallback UMKM).
     */
    private function isSoleActiveUser(User $user): bool
    {
        return User::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('is_active', true)
            ->count() === 1;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('purchasing.pr.view')
            || in_array($user->role?->name, ['Owner', 'Manager', 'Inventory', 'Admin'], true)
            || (bool) $user->role?->is_admin;
    }

    public function view(User $user, PurchaseRequest $pr): bool
    {
        return $this->sameTenant($user, $pr)
            && $this->hasStoreAccess($user, $pr)
            && ($user->hasPermission('purchasing.pr.view')
                || in_array($user->role?->name, ['Owner', 'Manager', 'Inventory', 'Admin'], true)
                || (bool) $user->role?->is_admin);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('purchasing.pr.create')
            || in_array($user->role?->name, ['Owner', 'Manager', 'Inventory', 'Admin'], true)
            || (bool) $user->role?->is_admin;
    }

    public function update(User $user, PurchaseRequest $pr): bool
    {
        return $this->sameTenant($user, $pr)
            && $this->hasStoreAccess($user, $pr)
            && $pr->isDraft()
            && ($user->hasPermission('purchasing.pr.update')
                || in_array($user->role?->name, ['Owner', 'Manager', 'Inventory', 'Admin'], true)
                || (bool) $user->role?->is_admin);
    }

    public function submit(User $user, PurchaseRequest $pr): bool
    {
        return $this->sameTenant($user, $pr)
            && $this->hasStoreAccess($user, $pr)
            && $pr->isDraft()
            && ($user->hasPermission('purchasing.pr.submit')
                || in_array($user->role?->name, ['Owner', 'Manager', 'Inventory', 'Admin'], true)
                || (bool) $user->role?->is_admin);
    }

    public function approve(User $user, PurchaseRequest $pr): bool
    {
        if (! $this->sameTenant($user, $pr)) {
            return false;
        }

        if (! $pr->isSubmitted()) {
            return false;
        }

        // Penegakan Pemisahan Tugas (Separation of Duties / SoD):
        // Pembuat PR dilarang menyetujui PR miliknya sendiri, kecuali jika ia adalah satu-satunya user aktif di tenant
        if ((int) $user->id === (int) $pr->requested_by_user_id && ! $this->isSoleActiveUser($user)) {
            return false;
        }

        return $user->hasPermission('purchasing.pr.approve')
            || in_array($user->role?->name, ['Owner', 'Manager', 'Admin'], true)
            || (bool) $user->role?->is_admin;
    }

    public function cancel(User $user, PurchaseRequest $pr): bool
    {
        if (! $this->sameTenant($user, $pr)) {
            return false;
        }

        if (! $pr->canBeCancelled()) {
            return false;
        }

        return (int) $user->id === (int) $pr->requested_by_user_id
            || $user->hasPermission('purchasing.pr.cancel')
            || in_array($user->role?->name, ['Owner', 'Manager', 'Admin'], true)
            || (bool) $user->role?->is_admin;
    }
}
