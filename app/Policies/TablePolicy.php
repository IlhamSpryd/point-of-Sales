<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Table;
use App\Models\User;

class TablePolicy
{
    private function canManage(User $user): bool
    {
        $role = $user->role?->name;

        return in_array($role, ['Owner', 'Manager'], true);
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Table $table): bool
    {
        return (int) $user->tenant_id === (int) $table->tenant_id;
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, Table $table): bool
    {
        return $this->canManage($user) && (int) $user->tenant_id === (int) $table->tenant_id;
    }

    public function delete(User $user, Table $table): bool
    {
        return $this->canManage($user) && (int) $user->tenant_id === (int) $table->tenant_id;
    }
}
