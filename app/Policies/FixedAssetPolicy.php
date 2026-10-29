<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Finance\Enums\FixedAssetStatusEnum;
use App\Modules\Finance\Models\FixedAsset;

class FixedAssetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('finance.fixed_assets.view')
            || $user->hasPermissionTo('finance.fixed_assets.manage')
            || $user->hasRole('owner');
    }

    public function view(User $user, FixedAsset $asset): bool
    {
        if ($user->tenant_id !== $asset->tenant_id) {
            return false;
        }

        return $user->hasPermissionTo('finance.fixed_assets.view')
            || $user->hasPermissionTo('finance.fixed_assets.manage')
            || $user->hasRole('owner');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('finance.fixed_assets.manage')
            || $user->hasRole('owner');
    }

    public function update(User $user, FixedAsset $asset): bool
    {
        if ($user->tenant_id !== $asset->tenant_id) {
            return false;
        }

        if ($asset->status !== FixedAssetStatusEnum::DRAFT) {
            return false;
        }

        return $user->hasPermissionTo('finance.fixed_assets.manage')
            || $user->hasRole('owner');
    }

    public function delete(User $user, FixedAsset $asset): bool
    {
        if ($user->tenant_id !== $asset->tenant_id) {
            return false;
        }

        if ($asset->status !== FixedAssetStatusEnum::DRAFT) {
            return false;
        }

        return $user->hasPermissionTo('finance.fixed_assets.manage')
            || $user->hasRole('owner');
    }

    public function capitalize(User $user, FixedAsset $asset): bool
    {
        if ($user->tenant_id !== $asset->tenant_id) {
            return false;
        }

        if ($asset->status !== FixedAssetStatusEnum::DRAFT) {
            return false;
        }

        return $user->hasPermissionTo('finance.fixed_assets.manage')
            || $user->hasRole('owner');
    }

    public function dispose(User $user, FixedAsset $asset): bool
    {
        if ($user->tenant_id !== $asset->tenant_id) {
            return false;
        }

        if (! in_array($asset->status, [FixedAssetStatusEnum::ACTIVE, FixedAssetStatusEnum::FULLY_DEPRECIATED], true)) {
            return false;
        }

        return $user->hasPermissionTo('finance.fixed_assets.manage')
            || $user->hasRole('owner');
    }
}
