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
