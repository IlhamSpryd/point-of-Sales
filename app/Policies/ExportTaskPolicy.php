<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ExportTask;
use App\Models\Role;
use App\Models\User;

class ExportTaskPolicy
{
    private function isOwnerOrManager(User $user): bool
    {
        $role = $user->relationLoaded('role') ? $user->role : $user->role()->withoutGlobalScopes()->first();
        if (! $role instanceof Role) {
            return false;
        }

        return in_array($role->name, ['Owner', 'Manager'], true) || (bool) $role->is_admin;
    }

    public function viewAny(User $user): bool
    {
        return $this->isOwnerOrManager($user);
    }

    public function view(User $user, ExportTask $task): bool
    {
        if ((int) $user->tenant_id !== (int) $task->tenant_id) {
            return false;
        }

        return $this->isOwnerOrManager($user) || (int) $task->requested_by === (int) $user->id;
    }

    public function download(User $user, ExportTask $task): bool
    {
        return $this->view($user, $task);
    }

    public function create(User $user): bool
    {
        return $this->isOwnerOrManager($user);
    }
}
