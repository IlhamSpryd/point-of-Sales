<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

/**
 * [D2-BE-004] Policy Order: kecocokan tenant_id & store_id WAJIB, ditambah
 * peran yang berwenang. Owner/Manager penuh; Kasir hanya buat & lihat.
 */
class OrderPolicy
{
    private function sameTenant(User $user, Order $order): bool
    {
        return (int) $user->tenant_id === (int) $order->tenant_id;
    }

    private function sameStore(User $user, Order $order): bool
    {
        return $user->store_id === null
            || (int) $user->store_id === (int) $order->store_id;
    }

    public function create(User $user): bool
    {
        return in_array($user->role?->name, ['Owner', 'Manager', 'Kasir'], true);
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Order $order): bool
    {
        return $this->sameTenant($user, $order) && $this->sameStore($user, $order);
    }

    public function void(User $user, Order $order): bool
    {
        return $this->sameTenant($user, $order)
            && $this->sameStore($user, $order)
            && in_array($user->role?->name, ['Owner', 'Manager', 'Supervisor'], true);
    }

    public function refund(User $user, Order $order): bool
    {
        return $this->sameTenant($user, $order)
            && $this->sameStore($user, $order)
            && in_array($user->role?->name, ['Owner', 'Manager'], true);
    }
}
