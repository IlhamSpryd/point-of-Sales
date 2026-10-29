<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Finance\Models\ApPayment;

class ApPaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('finance.ap.view') || $user->is_admin;
    }

    public function view(User $user, ApPayment $payment): bool
    {
        return $user->tenant_id === $payment->tenant_id
            && ($user->hasPermissionTo('finance.ap.view') || $user->is_admin);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('finance.ap.create') || $user->is_admin;
    }

    public function update(User $user, ApPayment $payment): bool
    {
        return $user->tenant_id === $payment->tenant_id
            && $payment->isDraft()
            && ($user->hasPermissionTo('finance.ap.create') || $user->is_admin);
    }

    public function approve(User $user, ApPayment $payment): bool
    {
        return $user->tenant_id === $payment->tenant_id
            && ($user->hasPermissionTo('finance.ap.approve_payment') || $user->is_admin);
    }

    public function post(User $user, ApPayment $payment): bool
    {
        return $user->tenant_id === $payment->tenant_id
            && ($user->hasPermissionTo('finance.ap.post_payment') || $user->is_admin);
    }

    public function void(User $user, ApPayment $payment): bool
    {
        return $user->tenant_id === $payment->tenant_id
            && ($user->hasPermissionTo('finance.ap.void_payment') || $user->is_admin);
    }
}
