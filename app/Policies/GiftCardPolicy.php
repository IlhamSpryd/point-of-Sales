<?php

declare(strict_types=1);

namespace App\Policies;

use App\Modules\Organization\Models\User;
use App\Modules\Pricing\Models\GiftCard;

class GiftCardPolicy
{
    private function hasRolePermission(User $user, string $permission): bool
    {
        // @tenant-bypass: auth_lookup
        $role = $user->relationLoaded('role') ? $user->role : $user->role()->withoutGlobalScopes()->first();
        if (! $role) {
            return false;
        }

        if ((bool) $role->is_admin || strtolower($role->name ?? '') === 'owner' || strtolower($role->name ?? '') === 'manager') {
            return true;
        }

        return $user->hasPermission($permission);
    }

    public function viewAny(User $user): bool
    {
        return $this->hasRolePermission($user, 'gift_cards.issue') || $this->hasRolePermission($user, 'customers.view');
    }

    public function view(User $user, GiftCard $giftCard): bool
    {
        if ((int) $user->tenant_id !== (int) $giftCard->tenant_id) {
            return false;
        }

        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->hasRolePermission($user, 'gift_cards.issue');
    }

    public function void(User $user, GiftCard $giftCard): bool
    {
        if ((int) $user->tenant_id !== (int) $giftCard->tenant_id) {
            return false;
        }

        return $this->hasRolePermission($user, 'gift_cards.void');
    }
}
