<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Finance\Models\ApPayment;

class ApPaymentPolicy
{
    private function isOwnerOrAdmin(User $user): bool
    {
        return (bool) $user->is_admin || strtolower($user->role->name ?? '') === 'owner';
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('finance.ap.view') || $this->isOwnerOrAdmin($user);
    }

    public function view(User $user, ApPayment $payment): bool
    {
        return $user->tenant_id === $payment->tenant_id
            && ($user->hasPermission('finance.ap.view') || $this->isOwnerOrAdmin($user));
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('finance.ap.create') || $this->isOwnerOrAdmin($user);
    }

    public function update(User $user, ApPayment $payment): bool
    {
        return $user->tenant_id === $payment->tenant_id
            && $payment->isDraft()
            && ($user->hasPermission('finance.ap.create') || $this->isOwnerOrAdmin($user));
    }

    public function approve(User $user, ApPayment $payment): bool
    {
        return $user->tenant_id === $payment->tenant_id
            && ($user->hasPermission('finance.ap.approve_payment') || $this->isOwnerOrAdmin($user));
    }

    public function post(User $user, ApPayment $payment): bool
    {
        return $user->tenant_id === $payment->tenant_id
            && ($user->hasPermission('finance.ap.post_payment') || $this->isOwnerOrAdmin($user));
    }

    public function void(User $user, ApPayment $payment): bool
    {
        return $user->tenant_id === $payment->tenant_id
            && ($user->hasPermission('finance.ap.void_payment') || $this->isOwnerOrAdmin($user));
    }
}
