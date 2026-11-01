<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\HR\Models\Employee;

class EmployeePolicy
{
    private function canManage(User $user): bool
    {
        return in_array($user->role?->name, ['Owner', 'Manager', 'Admin'], true)
            || (bool) ($user->role->is_admin ?? false)
            || $user->hasPermission('hr.employee.manage');
    }

    public function viewAny(User $user): bool
    {
        return $this->canManage($user) || $user->hasPermission('hr.employee.view');
    }

    public function view(User $user, Employee $employee): bool
    {
        if ((int) $user->tenant_id !== (int) $employee->tenant_id) {
            return false;
        }

        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, Employee $employee): bool
    {
        if ((int) $user->tenant_id !== (int) $employee->tenant_id) {
            return false;
        }

        return $this->canManage($user);
    }

    public function delete(User $user, Employee $employee): bool
    {
        if ((int) $user->tenant_id !== (int) $employee->tenant_id) {
            return false;
        }

        return $this->canManage($user);
    }
}
