<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Customers\Models\Voucher;

class VoucherPolicy
{
    private function hasRolePermission(User $user, string $permission): bool
    {
        $role = $user->relationLoaded('role') ? $user->role : $user->role()->withoutGlobalScopes()->first();
        if (! $role) {
            return false;
        }

        if ((bool) $role->is_admin || $role->name === 'Owner' || $role->name === 'Manager') {
            return true;
        }

        return $user->hasPermissionTo($permission);
    }

    public function viewAny(User $user): bool
    {
        return $this->hasRolePermission($user, 'vouchers.manage') || $this->hasRolePermission($user, 'customers.view');
    }

    public function view(User $user, Voucher $voucher): bool
    {
        if ((int) $user->tenant_id !== (int) $voucher->tenant_id) {
            return false;
        }

        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->hasRolePermission($user, 'vouchers.manage');
    }

    public function update(User $user, Voucher $voucher): bool
    {
        if ((int) $user->tenant_id !== (int) $voucher->tenant_id) {
            return false;
        }

        return $this->hasRolePermission($user, 'vouchers.manage');
    }

    public function delete(User $user, Voucher $voucher): bool
    {
        return $this->update($user, $voucher);
    }
}
