<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\GoodsReceipt;
use App\Models\User;

class GoodsReceiptPolicy
{
    private function canManage(User $user): bool
    {
        return in_array($user->role?->name, ['Owner', 'Manager', 'Inventory'], true);
    }

    private function canReverse(User $user): bool
    {
        return in_array($user->role?->name, ['Owner', 'Manager'], true);
    }

    private function hasBranchAccess(User $user, int $storeId): bool
    {
        if ($user->role?->name === 'Owner') {
            return true;
        }

        return $user->stores()->where('stores.id', $storeId)->exists();
    }

    private function sameTenantAndStore(User $user, GoodsReceipt $receipt): bool
    {
        if ((int) $user->tenant_id !== (int) $receipt->tenant_id) {
            return false;
        }

        return $this->hasBranchAccess($user, (int) $receipt->store_id);
    }

    public function viewAny(User $user): bool
    {
        return $this->canManage($user);
    }

    public function view(User $user, GoodsReceipt $receipt): bool
    {
        return $this->canManage($user) && $this->sameTenantAndStore($user, $receipt);
    }

    public function create(User $user, int $storeId): bool
    {
        return $this->canManage($user) && $this->hasBranchAccess($user, $storeId);
    }

    public function update(User $user, GoodsReceipt $receipt): bool
    {
        return $this->canManage($user)
            && $receipt->isDraft()
            && $this->sameTenantAndStore($user, $receipt);
    }

    public function cancel(User $user, GoodsReceipt $receipt): bool
    {
        return $this->canManage($user)
            && $receipt->isDraft()
            && $this->sameTenantAndStore($user, $receipt);
    }

    public function post(User $user, GoodsReceipt $receipt): bool
    {
        return $this->canManage($user)
            && $receipt->isDraft()
            && $this->sameTenantAndStore($user, $receipt);
    }

    public function reverse(User $user, GoodsReceipt $receipt): bool
    {
        return $this->canReverse($user)
            && $receipt->isPosted()
            && $this->sameTenantAndStore($user, $receipt);
    }
}
