<?php

declare(strict_types=1);

namespace App\Core\Traits;

use App\Core\Context\TenantContext;
use App\Models\Store;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

trait AssignsStore
{
    protected static function bootAssignsStore(): void
    {
        static::creating(function ($model) {
            $context = app(TenantContext::class);

            if (! empty($model->store_id)) {
                // Verifikasi terhadap context bila context eksplisit punya store.
                if ($context->getStoreId() !== null && $model->store_id !== $context->getStoreId()) {
                    throw new RuntimeException("Cannot create record for store {$model->store_id} while in context of store {$context->getStoreId()}");
                }

                return;
            }

            // 1. Store dari context (hasil resolusi server-side middleware/runAs).
            if ($context->getStoreId() !== null) {
                $model->store_id = $context->getStoreId();

                return;
            }

            // 2. Turunkan dari store pengguna yang sedang login.
            if ($user = Auth::user()) {
                $userStore = Store::query()
                    ->where('tenant_id', $model->tenant_id ?? $context->requireTenantId())
                    ->whereHas('users', fn ($q) => $q->where('users.id', $user->id))
                    ->orderBy('id')
                    ->value('id');

                if ($userStore !== null) {
                    $model->store_id = (int) $userStore;

                    return;
                }
            }

            // 3. Fallback sistem: tenant dengan tepat satu store.
            $tenantId = $model->tenant_id ?? $context->requireTenantId();
            $storeIds = Store::query()
                ->where('tenant_id', $tenantId)
                ->orderBy('id')
                ->pluck('id');

            if ($storeIds->count() === 1) {
                $model->store_id = (int) $storeIds->first();

                return;
            }

            throw new RuntimeException("Tidak dapat menentukan store untuk pembuatan record (tenant {$tenantId}).");
        });

        static::updating(function ($model) {
            if ($model->isDirty('store_id')) {
                throw new RuntimeException('Store ownership (store_id) cannot be modified once set.');
            }
        });
    }
}
