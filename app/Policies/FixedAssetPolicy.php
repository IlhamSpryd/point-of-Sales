<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Finance\Enums\FixedAssetStatusEnum;
use App\Modules\Finance\Models\FixedAsset;

class FixedAssetPolicy
{
    private function isOwner(User $user): bool
    {
        return strtolower($user->role->name ?? '') === 'owner';
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('finance.fixed_assets.view')
            || $user->hasPermission('finance.fixed_assets.manage')
            || $this->isOwner($user);
    }

    public function view(User $user, FixedAsset $asset): bool
    {
        if ($user->tenant_id !== $asset->tenant_id) {
            return false;
        }

        return $user->hasPermission('finance.fixed_assets.view')
            || $user->hasPermission('finance.fixed_assets.manage')
            || $this->isOwner($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('finance.fixed_assets.manage')
            || $this->isOwner($user);
    }

    public function update(User $user, FixedAsset $asset): bool
    {
        if ($user->tenant_id !== $asset->tenant_id) {
            return false;
        }

        if ($asset->status !== FixedAssetStatusEnum::DRAFT) {
            return false;
        }

        return $user->hasPermission('finance.fixed_assets.manage')
            || $this->isOwner($user);
    }

    public function delete(User $user, FixedAsset $asset): bool
    {
        if ($user->tenant_id !== $asset->tenant_id) {
            return false;
        }

        if ($asset->status !== FixedAssetStatusEnum::DRAFT) {
            return false;
        }

        return $user->hasPermission('finance.fixed_assets.manage')
            || $this->isOwner($user);
    }

    public function capitalize(User $user, FixedAsset $asset): bool
    {
        if ($user->tenant_id !== $asset->tenant_id) {
            return false;
        }

        if ($asset->status !== FixedAssetStatusEnum::DRAFT) {
            return false;
        }

        return $user->hasPermission('finance.fixed_assets.manage')
            || $this->isOwner($user);
    }

    public function dispose(User $user, FixedAsset $asset): bool
    {
        if ($user->tenant_id !== $asset->tenant_id) {
            return false;
        }

        if (! in_array($asset->status, [FixedAssetStatusEnum::ACTIVE, FixedAssetStatusEnum::FULLY_DEPRECIATED], true)) {
            return false;
        }

        return $user->hasPermission('finance.fixed_assets.manage')
            || $this->isOwner($user);
    }
}
