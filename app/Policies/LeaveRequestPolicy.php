<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\HR\Models\LeaveRequest;

class LeaveRequestPolicy
{
    private function canManage(User $user): bool
    {
        return in_array($user->role?->name, ['Owner', 'Manager', 'Admin'], true)
            || (bool) ($user->role->is_admin ?? false)
            || $user->hasPermission('hr.leave.manage');
    }

    public function viewAny(User $user): bool
    {
        return $this->canManage($user) || $user->hasPermission('hr.leave.view');
    }

    public function view(User $user, LeaveRequest $request): bool
    {
        if ((int) $user->tenant_id !== (int) $request->tenant_id) {
            return false;
        }

        return $this->viewAny($user);
    }

    public function approve(User $user, ?LeaveRequest $request = null): bool
    {
        if ($request && (int) $user->tenant_id !== (int) $request->tenant_id) {
            return false;
        }

        return $this->canManage($user);
    }

    public function reject(User $user, ?LeaveRequest $request = null): bool
    {
        if ($request && (int) $user->tenant_id !== (int) $request->tenant_id) {
            return false;
        }

        return $this->canManage($user);
    }
}
