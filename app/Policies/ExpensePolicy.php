<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Finance\Enums\ExpenseStatusEnum;
use App\Modules\Finance\Models\Expense;

class ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('finance.expense.view')
            || $user->hasPermissionTo('finance.expense.manage')
            || $user->hasRole('owner');
    }

    public function view(User $user, Expense $expense): bool
    {
        if ($user->tenant_id !== $expense->tenant_id) {
            return false;
        }

        return $user->hasPermissionTo('finance.expense.view')
            || $user->hasPermissionTo('finance.expense.manage')
            || $user->hasRole('owner')
            || $expense->submitted_by_user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('finance.expense.create')
            || $user->hasPermissionTo('finance.expense.manage')
            || $user->hasRole('owner');
    }

    public function update(User $user, Expense $expense): bool
    {
        if ($user->tenant_id !== $expense->tenant_id) {
            return false;
        }

        // Hanya DRAFT yang boleh diubah
        if ($expense->status !== ExpenseStatusEnum::DRAFT) {
            return false;
        }

        return $user->hasPermissionTo('finance.expense.manage')
            || $user->hasRole('owner')
            || $expense->submitted_by_user_id === $user->id;
    }

    public function delete(User $user, Expense $expense): bool
    {
        if ($user->tenant_id !== $expense->tenant_id) {
            return false;
        }

        // Hanya DRAFT yang boleh dihapus
        if ($expense->status !== ExpenseStatusEnum::DRAFT) {
            return false;
        }

        return $user->hasPermissionTo('finance.expense.manage')
            || $user->hasRole('owner')
            || $expense->submitted_by_user_id === $user->id;
    }

    public function submit(User $user, Expense $expense): bool
    {
        if ($user->tenant_id !== $expense->tenant_id) {
            return false;
        }

        return $expense->status === ExpenseStatusEnum::DRAFT;
    }

    /**
     * SoD (Separation of Duties):
     * Pengaju beban DILARANG menyetujui bebannya sendiri.
     */
    public function approve(User $user, Expense $expense): bool
    {
        if ($user->tenant_id !== $expense->tenant_id) {
            return false;
        }

        if ($expense->status !== ExpenseStatusEnum::SUBMITTED) {
            return false;
        }

        // SoD Enforce: Submitter cannot approve own expense
        if ($expense->submitted_by_user_id === $user->id) {
            return false;
        }

        return $user->hasPermissionTo('finance.expense.approve')
            || $user->hasPermissionTo('finance.expense.manage')
            || $user->hasRole('owner');
    }

    public function reject(User $user, Expense $expense): bool
    {
        if ($user->tenant_id !== $expense->tenant_id) {
            return false;
        }

        if ($expense->status !== ExpenseStatusEnum::SUBMITTED) {
            return false;
        }

        // SoD Enforce: Submitter cannot reject own expense
        if ($expense->submitted_by_user_id === $user->id) {
            return false;
        }

        return $user->hasPermissionTo('finance.expense.approve')
            || $user->hasPermissionTo('finance.expense.manage')
            || $user->hasRole('owner');
    }

    public function recordPayment(User $user, Expense $expense): bool
    {
        if ($user->tenant_id !== $expense->tenant_id) {
            return false;
        }

        if ($expense->status !== ExpenseStatusEnum::APPROVED) {
            return false;
        }

        return $user->hasPermissionTo('finance.expense.manage')
            || $user->hasRole('owner');
    }

    public function void(User $user, Expense $expense): bool
    {
        if ($user->tenant_id !== $expense->tenant_id) {
            return false;
        }

        if (in_array($expense->status, [ExpenseStatusEnum::DRAFT, ExpenseStatusEnum::REJECTED, ExpenseStatusEnum::VOIDED], true)) {
            return false;
        }

        return $user->hasPermissionTo('finance.expense.manage')
            || $user->hasRole('owner');
    }
}
