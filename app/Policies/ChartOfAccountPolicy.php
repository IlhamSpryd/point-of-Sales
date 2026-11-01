<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Finance\Models\ChartOfAccount;

class ChartOfAccountPolicy
{
    private function isOwner(User $user): bool
    {
        return strtolower($user->role->name ?? '') === 'owner';
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('finance.coa.view') || $this->isOwner($user);
    }

    public function view(User $user, ChartOfAccount $account): bool
    {
        if ($user->tenant_id !== $account->tenant_id) {
            return false;
        }

        return $user->hasPermission('finance.coa.view') || $this->isOwner($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('finance.coa.manage') || $this->isOwner($user);
    }

    public function update(User $user, ChartOfAccount $account): bool
    {
        if ($user->tenant_id !== $account->tenant_id) {
            return false;
        }

        return $user->hasPermission('finance.coa.manage') || $this->isOwner($user);
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

        return $user->hasPermission('finance.coa.manage') || $this->isOwner($user);
    }
}
