<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Products\Models\PriceList;

class PriceListPolicy
{
    private function canManage(User $user): bool
    {
        $role = $user->role?->name;

        return in_array($role, ['Owner', 'Manager'], true);
    }

    public function viewAny(User $user): bool
    {
        $role = $user->role?->name;

        return in_array($role, ['Owner', 'Manager', 'Inventory', 'Kasir'], true);
    }

    public function view(User $user, PriceList $priceList): bool
    {
        return (int) $user->tenant_id === (int) $priceList->tenant_id;
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, PriceList $priceList): bool
    {
        return $this->canManage($user) && (int) $user->tenant_id === (int) $priceList->tenant_id;
    }

    public function delete(User $user, PriceList $priceList): bool
    {
        return $this->canManage($user) && (int) $user->tenant_id === (int) $priceList->tenant_id;
    }
}
