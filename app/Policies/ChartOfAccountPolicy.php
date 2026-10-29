<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Finance\Models\ChartOfAccount;

class ChartOfAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('finance.coa.view') || $user->hasRole('owner');
    }

    public function view(User $user, ChartOfAccount $account): bool
    {
        if ($user->tenant_id !== $account->tenant_id) {
            return false;
        }

        return $user->hasPermissionTo('finance.coa.view') || $user->hasRole('owner');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('finance.coa.manage') || $user->hasRole('owner');
    }

    public function update(User $user, ChartOfAccount $account): bool
    {
        if ($user->tenant_id !== $account->tenant_id) {
            return false;
        }

        return $user->hasPermissionTo('finance.coa.manage') || $user->hasRole('owner');
    }

    public function delete(User $user, ChartOfAccount $account): bool
    {
        if ($user->tenant_id !== $account->tenant_id) {
            return false;
        }

        // Akun yang memiliki mutasi transaksi dilarang dihapus
        if ($account->hasTransactions()) {
            return false;
        }

        return $user->hasPermissionTo('finance.coa.manage') || $user->hasRole('owner');
    }
}
