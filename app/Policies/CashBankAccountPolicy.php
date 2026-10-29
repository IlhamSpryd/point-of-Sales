<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Finance\Models\CashBankAccount;

class CashBankAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('finance.cash_bank.view')
            || $user->hasPermissionTo('finance.cash_bank.manage')
            || $user->hasRole('owner');
    }

    public function view(User $user, CashBankAccount $account): bool
    {
        if ($user->tenant_id !== $account->tenant_id) {
            return false;
        }

        return $user->hasPermissionTo('finance.cash_bank.view')
            || $user->hasPermissionTo('finance.cash_bank.manage')
            || $user->hasRole('owner');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('finance.cash_bank.manage') || $user->hasRole('owner');
    }

    public function update(User $user, CashBankAccount $account): bool
    {
        if ($user->tenant_id !== $account->tenant_id) {
            return false;
        }

        return $user->hasPermissionTo('finance.cash_bank.manage') || $user->hasRole('owner');
    }

    public function delete(User $user, CashBankAccount $account): bool
    {
        if ($user->tenant_id !== $account->tenant_id) {
            return false;
        }

        // Akun kas/bank yang sudah memiliki mutasi transaksi dilarang dihapus
        if ($account->transactions()->exists()) {
            return false;
        }

        return $user->hasPermissionTo('finance.cash_bank.manage') || $user->hasRole('owner');
    }

    public function transfer(User $user, CashBankAccount $account): bool
    {
        if ($user->tenant_id !== $account->tenant_id) {
            return false;
        }

        return $user->hasPermissionTo('finance.cash_bank.transfer')
            || $user->hasPermissionTo('finance.cash_bank.manage')
            || $user->hasRole('owner');
    }

    public function reconcile(User $user, CashBankAccount $account): bool
    {
        if ($user->tenant_id !== $account->tenant_id) {
            return false;
        }

        return $user->hasPermissionTo('finance.cash_bank.reconcile')
            || $user->hasPermissionTo('finance.cash_bank.manage')
            || $user->hasRole('owner');
    }
}
