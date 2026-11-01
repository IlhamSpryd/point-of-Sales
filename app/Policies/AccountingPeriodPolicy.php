<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Finance\Models\AccountingPeriod;

class AccountingPeriodPolicy
{
    private function isOwner(User $user): bool
    {
        return strtolower($user->role->name ?? '') === 'owner';
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('finance.period.view') || $this->isOwner($user);
    }

    public function view(User $user, AccountingPeriod $period): bool
    {
        if ($user->tenant_id !== $period->tenant_id) {
            return false;
        }

        return $user->hasPermission('finance.period.view') || $this->isOwner($user);
    }

    public function manage(User $user, ?AccountingPeriod $period = null): bool
    {
        if ($period && $user->tenant_id !== $period->tenant_id) {
            return false;
        }

        return $user->hasPermission('finance.period.manage') || $this->isOwner($user);
    }

    public function close(User $user, ?AccountingPeriod $period = null): bool
    {
        if ($period && $user->tenant_id !== $period->tenant_id) {
            return false;
        }

        return $user->hasPermission('finance.period.close') || $this->isOwner($user);
    }

    public function reopen(User $user, ?AccountingPeriod $period = null): bool
    {
        if ($period && $user->tenant_id !== $period->tenant_id) {
            return false;
        }

        // Controlled Reopening membutuhkan permission eksplisit
        return $user->hasPermission('finance.period.reopen') || $this->isOwner($user);
    }
}
