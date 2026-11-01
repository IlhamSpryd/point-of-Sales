<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\HR\Models\PayrollBatch;

class PayrollBatchPolicy
{
    private function canManage(User $user): bool
    {
        return in_array($user->role?->name, ['Owner', 'Manager', 'Admin'], true)
            || (bool) ($user->role->is_admin ?? false)
            || $user->hasPermission('hr.payroll.manage');
    }

    public function viewAny(User $user): bool
    {
        return $this->canManage($user) || $user->hasPermission('hr.payroll.view');
    }

    public function view(User $user, PayrollBatch $batch): bool
    {
        if ((int) $user->tenant_id !== (int) $batch->tenant_id) {
            return false;
        }

        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function calculate(User $user, PayrollBatch $batch): bool
    {
        if ((int) $user->tenant_id !== (int) $batch->tenant_id) {
            return false;
        }

        return $this->canManage($user);
    }

    public function finalize(User $user, PayrollBatch $batch): bool
    {
        if ((int) $user->tenant_id !== (int) $batch->tenant_id) {
            return false;
        }

        return $this->canManage($user);
    }
}
