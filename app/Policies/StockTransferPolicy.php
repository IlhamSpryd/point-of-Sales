<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Inventory\Models\StockTransfer;

class StockTransferPolicy
{
    private function canManage(User $user): bool
    {
        return in_array($user->role?->name, ['Owner', 'Manager', 'Inventory'], true);
    }

    private function hasBranchAccess(User $user, int $storeId): bool
    {
        if ($user->role?->name === 'Owner') {
            return true;
        }

        return $user->stores()->where('stores.id', $storeId)->exists();
    }

    private function sameTenant(User $user, StockTransfer $transfer): bool
    {
        return (int) $user->tenant_id === (int) $transfer->tenant_id;
    }

    public function viewAny(User $user): bool
    {
        return $this->canManage($user);
    }

    public function view(User $user, StockTransfer $transfer): bool
    {
        if (! $this->canManage($user) || ! $this->sameTenant($user, $transfer)) {
            return false;
        }

        return $this->hasBranchAccess($user, (int) $transfer->from_store_id)
            || $this->hasBranchAccess($user, (int) $transfer->to_store_id);
    }

    public function create(User $user, ?int $fromStoreId = null, ?int $toStoreId = null): bool
    {
        if (! $this->canManage($user)) {
            return false;
        }

        if ($fromStoreId !== null && $toStoreId !== null) {
            if ($fromStoreId === $toStoreId) {
                return false;
            }

            return $this->hasBranchAccess($user, $fromStoreId);
        }

        return true;
    }

    public function ship(User $user, StockTransfer $transfer): bool
    {
        return $this->canManage($user)
            && $this->sameTenant($user, $transfer)
            && $transfer->isDraft()
            && $this->hasBranchAccess($user, (int) $transfer->from_store_id);
    }

    public function receive(User $user, StockTransfer $transfer): bool
    {
        // Penerima wajib memiliki akses ke cabang penerima (to_store_id)
        return $this->canManage($user)
            && $this->sameTenant($user, $transfer)
            && $transfer->isInTransit()
            && $this->hasBranchAccess($user, (int) $transfer->to_store_id);
    }

    public function cancel(User $user, StockTransfer $transfer): bool
    {
        if (! $this->canManage($user) || ! $this->sameTenant($user, $transfer)) {
            return false;
        }

        if ($transfer->isDraft()) {
            return $this->hasBranchAccess($user, (int) $transfer->from_store_id);
        }

        if ($transfer->isInTransit()) {
            return $this->hasBranchAccess($user, (int) $transfer->from_store_id)
                || $this->hasBranchAccess($user, (int) $transfer->to_store_id);
        }

        return false;
    }
}
