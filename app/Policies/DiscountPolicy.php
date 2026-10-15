<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Discount;
use App\Models\User;

/**
 * [D2-BE-004] Policy Discount: CRUD diskon hanya Owner & Manager,
 * dengan kecocokan tenant (dan store bila diskon ber-scope cabang).
 */
class DiscountPolicy
{
    private function canManage(User $user): bool
    {
        return in_array($user->role?->name, ['Owner', 'Manager'], true);
    }

    private function hasStoreAccess(User $user, Discount $discount): bool
    {
        if ((int) $user->tenant_id !== (int) $discount->tenant_id) {
            return false;
        }

        if ($discount->store_id === null) {
            return true;
        }

        $role = $user->role?->name;
        if ($role === 'Owner' || (bool) ($user->role?->is_admin ?? false)) {
            return true;
        }

        if (isset($user->store_id) && $user->store_id !== null) {
            return (int) $user->store_id === (int) $discount->store_id;
        }

        return $user->stores()->where('stores.id', $discount->store_id)->exists();
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Discount $discount): bool
    {
        return $this->hasStoreAccess($user, $discount);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, Discount $discount): bool
    {
        return $this->canManage($user) && $this->hasStoreAccess($user, $discount);
    }

    public function delete(User $user, Discount $discount): bool
    {
        return $this->canManage($user) && $this->hasStoreAccess($user, $discount);
    }
}
