<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Role;
use App\Models\User;
use App\Models\UserInvitation;

class UserInvitationPolicy
{
    private function isOwner(User $user): bool
    {
        $role = $user->relationLoaded('role') ? $user->role : $user->role()->withoutGlobalScopes()->first();
        if (! $role instanceof Role) {
            return false;
        }

        return $role->name === 'Owner' || (bool) $role->is_admin;
    }

    public function viewAny(User $user): bool
    {
        return $this->isOwner($user);
    }

    public function view(User $user, UserInvitation $invitation): bool
    {
        return $this->isOwner($user) && (int) $user->tenant_id === (int) $invitation->tenant_id;
    }

    public function create(User $user): bool
    {
        return $this->isOwner($user);
    }

    public function resend(User $user, UserInvitation $invitation): bool
    {
        return $this->isOwner($user) && (int) $user->tenant_id === (int) $invitation->tenant_id;
    }

    public function revoke(User $user, UserInvitation $invitation): bool
    {
        return $this->isOwner($user) && (int) $user->tenant_id === (int) $invitation->tenant_id;
    }
}
