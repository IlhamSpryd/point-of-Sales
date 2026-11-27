<?php

declare(strict_types=1);

namespace App\Policies;

use App\Modules\Organization\Models\ActivityLog;
use App\Modules\Organization\Models\Role;
use App\Modules\Organization\Models\User;

class ActivityLogPolicy
{
    private function canView(User $user): bool
    {
        // @tenant-bypass: auth_lookup
        $role = $user->relationLoaded('role') ? $user->role : $user->role()->withoutGlobalScopes()->first();
        if (! $role instanceof Role) {
            return false;
        }

        return in_array($role->name, ['Owner', 'Admin', 'Manager'], true) || (bool) $role->is_admin;
    }

    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, ActivityLog $activityLog): bool
    {
        return $this->canView($user) && (int) $user->tenant_id === (int) $activityLog->tenant_id;
    }
}
