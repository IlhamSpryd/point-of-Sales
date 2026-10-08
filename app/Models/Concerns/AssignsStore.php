<?php

namespace App\Models\Concerns;

use App\Services\Context\StoreResolver;
use App\Services\Context\TenantContext;

trait AssignsStore
{
    protected static function bootAssignsStore()
    {
        static::creating(function ($model) {
            $context = app(TenantContext::class);

            if (empty($model->store_id)) {
                // If context has a store_id, use it. Otherwise derive from tenant's single store.
                if ($context->getStoreId() !== null) {
                    $model->store_id = $context->getStoreId();
                } else {
                    $tenantId = $model->tenant_id ?? $context->requireTenantId();
                    $model->store_id = StoreResolver::forTenant($tenantId);
                }
            } else {
                // Verify against context if context explicitly has a store
                if ($context->getStoreId() !== null && $model->store_id !== $context->getStoreId()) {
                    throw new \RuntimeException("Cannot create record for store {$model->store_id} while in context of store {$context->getStoreId()}");
                }
            }
        });
    }
}
