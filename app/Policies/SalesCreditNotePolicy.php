<?php

declare(strict_types=1);

namespace App\Policies;

use App\Modules\Organization\Models\User;
use App\Modules\Sales\Models\SalesCreditNote;

class SalesCreditNotePolicy
{
    public function viewAny(User $user): bool
    {
        return (bool) ($user->role->is_admin ?? false)
            || $user->hasPermission('credit_notes.view')
            || $user->hasPermission('credit_notes.apply')
            || $user->hasPermission('returns.view')
            || $user->hasPermission('sales.returns.view')
            || $user->hasPermission('sales.*')
            || $user->hasPermission('finance.*')
            || $user->hasPermission('orders.*');
    }

    public function view(User $user, SalesCreditNote $creditNote): bool
    {
        return (int) $user->tenant_id === (int) $creditNote->tenant_id
            && $this->viewAny($user);
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
        if ((bool) ($user->role->is_admin ?? false)) {
            return true;
        }

        return $user->hasPermission($permission)
            || $user->hasPermission('finance.*')
            || $user->hasPermission('orders.*')
            || $user->hasPermission('sales.*')
            || (bool) $user->role?->hasPermission($permission);
    }
}
