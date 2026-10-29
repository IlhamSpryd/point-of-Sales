<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Finance\Models\AccountingPeriod;

class AccountingPeriodPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('finance.period.view') || $user->hasRole('owner');
    }

    public function view(User $user, AccountingPeriod $period): bool
    {
        if ($user->tenant_id !== $period->tenant_id) {
            return false;
        }

        return $user->hasPermissionTo('finance.period.view') || $user->hasRole('owner');
    }

    public function manage(User $user, AccountingPeriod $period): bool
    {
        if ($user->tenant_id !== $period->tenant_id) {
            return false;
        }

        return $user->hasPermissionTo('finance.period.manage') || $user->hasRole('owner');
    }

    public function close(User $user, AccountingPeriod $period): bool
    {
        if ($user->tenant_id !== $period->tenant_id) {
            return false;
        }

        return $user->hasPermissionTo('finance.period.close') || $user->hasRole('owner');
    }

    public function reopen(User $user, AccountingPeriod $period): bool
    {
        if ($user->tenant_id !== $period->tenant_id) {
            return false;
        }

        // Controlled Reopening membutuhkan permission eksplisit
        return $user->hasPermissionTo('finance.period.reopen') || $user->hasRole('owner');
    }
}
