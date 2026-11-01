<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\HR\Models\AttendanceRecord;

class AttendanceRecordPolicy
{
    private function canManage(User $user): bool
    {
        return in_array($user->role?->name, ['Owner', 'Manager', 'Admin'], true)
            || (bool) ($user->role->is_admin ?? false)
            || $user->hasPermission('hr.attendance.manage');
    }

    public function viewAny(User $user): bool
    {
        return $this->canManage($user) || $user->hasPermission('hr.attendance.view');
    }

    public function view(User $user, AttendanceRecord $record): bool
    {
        if ((int) $user->tenant_id !== (int) $record->tenant_id) {
            return false;
        }

        return $this->viewAny($user);
    }

    public function update(User $user, ?AttendanceRecord $record = null): bool
    {
        if ($record && (int) $user->tenant_id !== (int) $record->tenant_id) {
            return false;
        }

        return $this->canManage($user);
    }
}
