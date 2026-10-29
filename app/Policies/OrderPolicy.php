<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Sales\Models\Order;

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
        $role = $user->role;
        if ($role && ($role->name === 'Owner' || (bool) $role->is_admin)) {
            return true;
        }

        if (isset($user->store_id)) {
            return (int) $user->store_id === (int) $order->store_id;
        }

        return $user->stores()->where('stores.id', $order->store_id)->exists();
    }

    public function create(User $user): bool
    {
        return in_array($user->role?->name, ['Owner', 'Manager', 'Kasir', 'Cashier', 'Admin'], true)
            || (bool) $user->role?->is_admin
            || (bool) $user->role?->hasPermission('can_create_order');
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
            && (in_array($user->role?->name, ['Owner', 'Manager', 'Supervisor', 'Admin'], true)
                || (bool) $user->role?->is_admin
                || (bool) $user->role?->hasPermission('can_void_order')
                || (bool) $user->role?->hasPermission('orders.void')
                || (bool) $user->role?->hasPermission('void.approve'));
    }

    public function refund(User $user, Order $order): bool
    {
        return $this->sameTenant($user, $order)
            && $this->sameStore($user, $order)
            && (in_array($user->role?->name, ['Owner', 'Manager', 'Supervisor', 'Admin'], true)
                || (bool) $user->role?->is_admin
                || (bool) $user->role?->hasPermission('can_refund_order')
                || (bool) $user->role?->hasPermission('orders.refund'));
    }

    public function return(User $user, Order $order): bool
    {
        return $this->sameTenant($user, $order)
            && $this->sameStore($user, $order)
            && (in_array($user->role?->name, ['Owner', 'Manager', 'Supervisor', 'Admin'], true)
                || (bool) $user->role?->is_admin
                || (bool) $user->role?->hasPermission('can_return_order')
                || (bool) $user->role?->hasPermission('orders.return')
                || $this->refund($user, $order));
    }
}
