<?php

declare(strict_types=1);

namespace App\Policies;

use App\Modules\Organization\Models\Role;
use App\Modules\Organization\Models\User;
use App\Modules\Organization\Models\UserInvitation;

class UserInvitationPolicy
{
    private function isOwnerOrAdmin(User $user): bool
    {
        $role = $user->role;
        if (! $role instanceof Role) {
            return false;
        }

        return in_array($role->name, ['Owner', 'Admin'], true) || (bool) $role->is_admin;
    }

    public function viewAny(User $user): bool
    {
        return $this->isOwnerOrAdmin($user);
    }

    public function view(User $user, UserInvitation $invitation): bool
    {
        return $this->isOwnerOrAdmin($user) && (int) $user->tenant_id === (int) $invitation->tenant_id;
    }

    public function create(User $user): bool
    {
        return $this->isOwnerOrAdmin($user);
    }

    public function resend(User $user, UserInvitation $invitation): bool
    {
        return $this->isOwnerOrAdmin($user) && (int) $user->tenant_id === (int) $invitation->tenant_id;
    }

    public function revoke(User $user, UserInvitation $invitation): bool
    {
        return $this->isOwnerOrAdmin($user) && (int) $user->tenant_id === (int) $invitation->tenant_id;
    }
}
