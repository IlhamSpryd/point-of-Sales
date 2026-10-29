<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Inventory\Models\StockAdjustment;

class StockAdjustmentPolicy
{
    private function canManage(User $user): bool
    {
        return in_array($user->role?->name, ['Owner', 'Manager', 'Inventory'], true)
            || (bool) ($user->role?->is_admin ?? false);
    }

    private function hasBranchAccess(User $user, int $storeId): bool
    {
        if ($user->role?->name === 'Owner' || (bool) ($user->role?->is_admin ?? false)) {
            return true;
        }

        return $user->stores()->where('stores.id', $storeId)->exists();
    }

    private function sameTenantAndStore(User $user, StockAdjustment $adjustment): bool
    {
        if ((int) $user->tenant_id !== (int) $adjustment->tenant_id) {
            return false;
        }

        return $this->hasBranchAccess($user, (int) $adjustment->store_id);
    }

    public function viewAny(User $user): bool
    {
        return $this->canManage($user);
    }

    public function view(User $user, StockAdjustment $adjustment): bool
    {
        return $this->canManage($user) && $this->sameTenantAndStore($user, $adjustment);
    }

    public function create(User $user, int $storeId): bool
    {
        return $this->canManage($user) && $this->hasBranchAccess($user, $storeId);
    }

    public function update(User $user, StockAdjustment $adjustment): bool
    {
        return $this->canManage($user)
            && $adjustment->isDraft()
            && $this->sameTenantAndStore($user, $adjustment);
    }

    public function cancel(User $user, StockAdjustment $adjustment): bool
    {
        return $this->canManage($user)
            && $adjustment->isDraft()
            && $this->sameTenantAndStore($user, $adjustment);
    }

    public function post(User $user, StockAdjustment $adjustment): bool
    {
        return $this->canManage($user)
            && $adjustment->isDraft()
            && $this->sameTenantAndStore($user, $adjustment);
    }
}
