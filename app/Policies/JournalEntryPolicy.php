<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Finance\Models\JournalEntry;

class JournalEntryPolicy
{
    private function isOwner(User $user): bool
    {
        return strtolower($user->role->name ?? '') === 'owner';
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('finance.journal.view') || $this->isOwner($user);
    }

    public function view(User $user, JournalEntry $journal): bool
    {
        if ($user->tenant_id !== $journal->tenant_id) {
            return false;
        }

        return $user->hasPermission('finance.journal.view') || $this->isOwner($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('finance.journal.create') || $this->isOwner($user);
    }

    public function approve(User $user, JournalEntry $journal): bool
    {
        if ($user->tenant_id !== $journal->tenant_id) {
            return false;
        }

        // Separation of Duties: pembuat draf dilarang menyetujui sendiri kecuali Owner
        if ($journal->created_by_user_id === $user->id && ! $this->isOwner($user)) {
            return false;
        }

        return $user->hasPermission('finance.journal.approve') || $this->isOwner($user);
    }

    public function post(User $user, JournalEntry $journal): bool
    {
        if ($user->tenant_id !== $journal->tenant_id) {
            return false;
        }

        return $user->hasPermission('finance.journal.post') || $this->isOwner($user);
    }

    public function reverse(User $user, JournalEntry $journal): bool
    {
        if ($user->tenant_id !== $journal->tenant_id) {
            return false;
        }

        return $user->hasPermission('finance.journal.reverse') || $this->isOwner($user);
    }
}
