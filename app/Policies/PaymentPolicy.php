<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    private function sameTenant(User $user, Payment $payment): bool
    {
        return (int) $user->tenant_id === (int) $payment->tenant_id;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('payments.view')
            || in_array($user->role?->name, ['Owner', 'Manager', 'Admin', 'Kasir', 'Finance'], true)
            || (bool) $user->role?->is_admin;
    }

    public function view(User $user, Payment $payment): bool
    {
        return $this->sameTenant($user, $payment)
            && ($user->hasPermission('payments.view')
                || in_array($user->role?->name, ['Owner', 'Manager', 'Admin', 'Kasir', 'Finance'], true)
                || (bool) $user->role?->is_admin);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('payments.create')
            || in_array($user->role?->name, ['Owner', 'Manager', 'Admin', 'Kasir'], true)
            || (bool) $user->role?->is_admin;
    }

    public function update(User $user, Payment $payment): bool
    {
        return $this->sameTenant($user, $payment)
            && ($user->hasPermission('payments.update')
                || in_array($user->role?->name, ['Owner', 'Manager', 'Admin'], true)
                || (bool) $user->role?->is_admin);
    }

    public function delete(User $user, Payment $payment): bool
    {
        return $this->sameTenant($user, $payment)
            && ($user->hasPermission('payments.delete')
                || in_array($user->role?->name, ['Owner', 'Admin'], true)
                || (bool) $user->role?->is_admin);
    }

    public function refund(User $user, Payment $payment): bool
    {
        return $this->sameTenant($user, $payment)
            && ($user->hasPermission('payments.refund')
                || in_array($user->role?->name, ['Owner', 'Manager', 'Admin', 'Supervisor'], true)
                || (bool) $user->role?->is_admin);
    }

    public function approve(User $user, Payment $payment): bool
    {
        return $this->sameTenant($user, $payment)
            && ($user->hasPermission('payments.approve')
                || in_array($user->role?->name, ['Owner', 'Manager', 'Finance', 'Admin'], true)
                || (bool) $user->role?->is_admin);
    }
}
