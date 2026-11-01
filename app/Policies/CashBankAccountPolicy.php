<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Finance\Models\CashBankAccount;

class CashBankAccountPolicy
{
    private function isOwner(User $user): bool
    {
        return strtolower($user->role->name ?? '') === 'owner';
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('finance.cash_bank.view')
            || $user->hasPermission('finance.cash_bank.manage')
            || $this->isOwner($user);
    }

    public function view(User $user, CashBankAccount $account): bool
    {
        if ($user->tenant_id !== $account->tenant_id) {
            return false;
        }

        return $user->hasPermission('finance.cash_bank.view')
            || $user->hasPermission('finance.cash_bank.manage')
            || $this->isOwner($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('finance.cash_bank.manage') || $this->isOwner($user);
    }

    public function update(User $user, CashBankAccount $account): bool
    {
        if ($user->tenant_id !== $account->tenant_id) {
            return false;
        }

        return $user->hasPermission('finance.cash_bank.manage') || $this->isOwner($user);
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

        return $user->hasPermission('finance.cash_bank.manage') || $this->isOwner($user);
    }

    public function transfer(User $user, CashBankAccount $account): bool
    {
        if ($user->tenant_id !== $account->tenant_id) {
            return false;
        }

        return $user->hasPermission('finance.cash_bank.transfer')
            || $user->hasPermission('finance.cash_bank.manage')
            || $this->isOwner($user);
    }

    public function reconcile(User $user, CashBankAccount $account): bool
    {
        if ($user->tenant_id !== $account->tenant_id) {
            return false;
        }

        return $user->hasPermission('finance.cash_bank.reconcile')
            || $user->hasPermission('finance.cash_bank.manage')
            || $this->isOwner($user);
    }
}
