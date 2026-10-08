<?php

namespace App\Services\Context;

use App\Models\Store;
use RuntimeException;

class StoreResolver
{
    public static function forTenant(int $tenantId): int
    {
        // For Phase 11, resolve to the tenant's only store, else throw
        $stores = Store::where('tenant_id', $tenantId)->get();
        if ($stores->count() !== 1) {
            throw new RuntimeException("Tenant {$tenantId} does not have exactly one store. StoreResolver requires 1 store per tenant in this phase.");
        }

        return $stores->first()->id;
    }
}
