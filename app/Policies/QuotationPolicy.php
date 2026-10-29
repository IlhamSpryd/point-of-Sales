<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Sales\Models\Quotation;

class QuotationPolicy
{
    private function sameTenant(User $user, Quotation $quotation): bool
    {
        return (int) $user->tenant_id === (int) $quotation->tenant_id;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('quotations.view')
            || in_array($user->role?->name, ['Owner', 'Manager', 'Admin', 'Sales'], true)
            || (bool) $user->role?->is_admin;
    }

    public function view(User $user, Quotation $quotation): bool
    {
        return $this->sameTenant($user, $quotation)
            && ($user->hasPermission('quotations.view')
                || in_array($user->role?->name, ['Owner', 'Manager', 'Admin', 'Sales'], true)
                || (bool) $user->role?->is_admin);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('quotations.create')
            || in_array($user->role?->name, ['Owner', 'Manager', 'Admin', 'Sales'], true)
            || (bool) $user->role?->is_admin;
    }

    public function update(User $user, Quotation $quotation): bool
    {
        return $this->sameTenant($user, $quotation)
            && ($user->hasPermission('quotations.update')
                || in_array($user->role?->name, ['Owner', 'Manager', 'Admin', 'Sales'], true)
                || (bool) $user->role?->is_admin);
    }

    public function approve(User $user, Quotation $quotation): bool
    {
        return $this->sameTenant($user, $quotation)
            && ($user->hasPermission('quotations.approve')
                || in_array($user->role?->name, ['Owner', 'Manager', 'Admin'], true)
                || (bool) $user->role?->is_admin);
    }

    public function convert(User $user, Quotation $quotation): bool
    {
        return $this->sameTenant($user, $quotation)
            && ($user->hasPermission('quotations.convert')
                || in_array($user->role?->name, ['Owner', 'Manager', 'Admin', 'Sales'], true)
                || (bool) $user->role?->is_admin);
    }

    public function delete(User $user, Quotation $quotation): bool
    {
        return $this->sameTenant($user, $quotation)
            && ($user->hasPermission('quotations.delete')
                || in_array($user->role?->name, ['Owner', 'Admin'], true)
                || (bool) $user->role?->is_admin);
    }
}
