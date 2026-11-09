<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('tenant.{tenantId}.store.{storeId}.waiter-calls', function (User $user, int|string $tenantId, int|string $storeId): bool {
    if ((int) $user->tenant_id !== (int) $tenantId) {
        return false;
    }

    $role = $user->role;
    if ($role && ($role->name === 'Owner' || (bool) $role->is_admin)) {
        return true;
    }

    if (isset($user->store_id)) {
        return (int) $user->store_id === (int) $storeId;
    }

    return $user->stores()->where('stores.id', (int) $storeId)->exists();
});

Broadcast::channel('tenant.{tenantId}.store.{storeId}.kds', function (User $user, int|string $tenantId, int|string $storeId): bool {
    if ((int) $user->tenant_id !== (int) $tenantId) {
        return false;
    }

    $role = $user->role;
    $hasKdsAccess = $role && (
        $role->name === 'Owner' ||
        $role->name === 'Cook' ||
        $role->name === 'Barista' ||
        (bool) $role->is_admin ||
        $role->hasPermission('*') ||
        $role->hasPermission('kds.access') ||
        $role->hasPermission('kds.view') ||
        $role->hasPermission('orders.*')
    );

    if (! $hasKdsAccess) {
        return false;
    }

    if ($role->name === 'Owner' || (bool) $role->is_admin) {
        return true;
    }

    if ($user->stores()->exists()) {
        return $user->stores()->where('stores.id', (int) $storeId)->exists();
    }

    return true;
});

Broadcast::channel('tenant.{tenantId}.kds', function (User $user, int|string $tenantId): bool {
    if ((int) $user->tenant_id !== (int) $tenantId) {
        return false;
    }

    $role = $user->role;
    return (bool) ($role && (
        $role->name === 'Owner' ||
        $role->name === 'Cook' ||
        $role->name === 'Barista' ||
        (bool) $role->is_admin ||
        $role->hasPermission('*') ||
        $role->hasPermission('kds.access') ||
        $role->hasPermission('kds.view') ||
        $role->hasPermission('orders.*')
    ));
});
