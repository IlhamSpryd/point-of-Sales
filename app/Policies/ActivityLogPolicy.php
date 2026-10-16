<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ActivityLog;
use App\Models\User;

class ActivityLogPolicy
{
    private function canView(User $user): bool
    {
        $role = $user->relationLoaded('role') ? $user->role : $user->role()->withoutGlobalScopes()->first();

        return in_array($role?->name, ['Owner', 'Manager'], true) || (bool) ($role?->is_admin ?? false);
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
