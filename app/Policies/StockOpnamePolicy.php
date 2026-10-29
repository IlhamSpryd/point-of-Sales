<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\StockOpnameStatusEnum;
use App\Models\User;
use App\Modules\Inventory\Models\StockOpname;

class StockOpnamePolicy
{
    private function canManage(User $user): bool
    {
        return in_array($user->role?->name, ['Owner', 'Manager', 'Inventory'], true)
            || (bool) ($user->role?->is_admin ?? false);
    }

    private function canApproveRole(User $user): bool
    {
        // Segregation of Duties: Approver wajib role Manager atau Owner (OD-04 / RBAC)
        return in_array($user->role?->name, ['Owner', 'Manager'], true)
            || (bool) ($user->role?->is_admin ?? false);
    }

    private function hasBranchAccess(User $user, int $storeId): bool
    {
        if ($user->role?->name === 'Owner' || (bool) ($user->role?->is_admin ?? false)) {
            return true;
        }

        return $user->stores()->where('stores.id', $storeId)->exists();
    }

    private function sameTenantAndStore(User $user, StockOpname $opname): bool
    {
        if ((int) $user->tenant_id !== (int) $opname->tenant_id) {
            return false;
        }

        return $this->hasBranchAccess($user, (int) $opname->store_id);
    }

    public function viewAny(User $user): bool
    {
        return $this->canManage($user);
    }

    public function view(User $user, StockOpname $opname): bool
    {
        return $this->canManage($user) && $this->sameTenantAndStore($user, $opname);
    }

    public function create(User $user, int $storeId): bool
    {
        return $this->canManage($user) && $this->hasBranchAccess($user, $storeId);
    }

    public function startCounting(User $user, StockOpname $opname): bool
    {
        return $this->canManage($user)
            && $opname->isDraft()
            && $this->sameTenantAndStore($user, $opname);
    }

    public function recordCount(User $user, StockOpname $opname): bool
    {
        return $this->canManage($user)
            && $opname->isCounting()
            && $this->sameTenantAndStore($user, $opname);
    }

    public function submit(User $user, StockOpname $opname): bool
    {
        return $this->canManage($user)
            && $opname->isCounting()
            && $this->sameTenantAndStore($user, $opname);
    }

    public function approve(User $user, StockOpname $opname): bool
    {
        // Petugas hitung / inventory biasa atau kasir tidak boleh approve
        return $this->canApproveRole($user)
            && $opname->isPendingApproval()
            && $this->sameTenantAndStore($user, $opname);
    }

    public function cancel(User $user, StockOpname $opname): bool
    {
        return $this->canManage($user)
            && in_array($opname->status, [
                StockOpnameStatusEnum::Draft,
                StockOpnameStatusEnum::Counting,
                StockOpnameStatusEnum::PendingApproval,
            ], true)
            && $this->sameTenantAndStore($user, $opname);
    }
}
