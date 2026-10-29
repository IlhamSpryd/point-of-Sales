<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Sales\Models\SalesCreditNote;

class SalesCreditNotePolicy
{
    protected array $financeRoles = ['Owner', 'Manager', 'Finance', 'Admin'];

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SalesCreditNote $creditNote): bool
    {
        return (int) $user->tenant_id === (int) $creditNote->tenant_id;
    }

    public function create(User $user): bool
    {
        return $this->hasAccess($user, 'credit_notes.issue');
    }

    public function apply(User $user, SalesCreditNote $creditNote): bool
    {
        if ((int) $user->tenant_id !== (int) $creditNote->tenant_id) {
            return false;
        }

        return $this->hasAccess($user, 'credit_notes.apply');
    }

    public function delete(User $user, SalesCreditNote $creditNote): bool
    {
        return false;
    }

    protected function hasAccess(User $user, string $permission): bool
    {
        $roleName = $user->role?->name;

        if (in_array($roleName, $this->financeRoles, true)) {
            return true;
        }

        return (bool) $user->role?->hasPermission($permission);
    }
}
