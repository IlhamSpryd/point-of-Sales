<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Inventory\Models\SupplierReturn;

class SupplierReturnPolicy
{
    private function canManage(User $user): bool
    {
        return in_array($user->role?->name, ['Owner', 'Manager', 'Inventory'], true)
            || (bool) ($user->role?->is_admin ?? false);
    }

    private function canReverse(User $user): bool
    {
        return in_array($user->role?->name, ['Owner', 'Manager'], true)
            || (bool) ($user->role?->is_admin ?? false);
    }

    private function hasBranchAccess(User $user, int $storeId): bool
    {
        if ($user->role?->name === 'Owner' || (bool) ($user->role?->is_admin ?? false)) {
            return true;
        }

        return $user->stores()->where('stores.id', $storeId)->exists();
    }

    private function sameTenantAndStore(User $user, SupplierReturn $return): bool
    {
        if ((int) $user->tenant_id !== (int) $return->tenant_id) {
            return false;
        }

        return $this->hasBranchAccess($user, (int) $return->store_id);
    }

    public function viewAny(User $user): bool
    {
        return $this->canManage($user);
    }

    public function view(User $user, SupplierReturn $return): bool
    {
        return $this->canManage($user) && $this->sameTenantAndStore($user, $return);
    }

    public function create(User $user, ?int $storeId = null): bool
    {
        if (! $this->canManage($user)) {
            return false;
        }

        if ($storeId !== null) {
            return $this->hasBranchAccess($user, $storeId);
        }

        return true;
    }

    public function update(User $user, SupplierReturn $return): bool
    {
        return $this->canManage($user)
            && $return->isDraft()
            && $this->sameTenantAndStore($user, $return);
    }

    public function cancel(User $user, SupplierReturn $return): bool
    {
        return $this->canManage($user)
            && $return->isDraft()
            && $this->sameTenantAndStore($user, $return);
    }

    public function post(User $user, SupplierReturn $return): bool
    {
        return $this->canManage($user)
            && $return->isDraft()
            && $this->sameTenantAndStore($user, $return);
    }

    public function reverse(User $user, SupplierReturn $return): bool
    {
        return $this->canReverse($user)
            && $return->isPosted()
            && $this->sameTenantAndStore($user, $return);
    }
}
