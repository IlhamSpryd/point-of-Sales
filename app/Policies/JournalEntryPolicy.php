<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Finance\Models\JournalEntry;

class JournalEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('finance.journal.view') || $user->hasRole('owner');
    }

    public function view(User $user, JournalEntry $journal): bool
    {
        if ($user->tenant_id !== $journal->tenant_id) {
            return false;
        }

        return $user->hasPermissionTo('finance.journal.view') || $user->hasRole('owner');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('finance.journal.create') || $user->hasRole('owner');
    }

    public function approve(User $user, JournalEntry $journal): bool
    {
        if ($user->tenant_id !== $journal->tenant_id) {
            return false;
        }

        // Separation of Duties: pembuat draf dilarang menyetujui sendiri kecuali Owner
        if ($journal->created_by_user_id === $user->id && ! $user->hasRole('owner')) {
            return false;
        }

        return $user->hasPermissionTo('finance.journal.approve') || $user->hasRole('owner');
    }

    public function post(User $user, JournalEntry $journal): bool
    {
        if ($user->tenant_id !== $journal->tenant_id) {
            return false;
        }

        return $user->hasPermissionTo('finance.journal.post') || $user->hasRole('owner');
    }

    public function reverse(User $user, JournalEntry $journal): bool
    {
        if ($user->tenant_id !== $journal->tenant_id) {
            return false;
        }

        return $user->hasPermissionTo('finance.journal.reverse') || $user->hasRole('owner');
    }
}
