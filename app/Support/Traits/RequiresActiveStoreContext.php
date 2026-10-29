<?php

declare(strict_types=1);

namespace App\Support\Traits;

use App\Models\Device;
use App\Models\Store;
use App\Services\Context\TenantContext;
use DomainException;

/**
 * Enforces strict multi-tenant Store and Device context resolution.
 * Prevents hardcoded `?? 1` fallbacks that could leak operational records
 * across tenant or branch boundaries (GAP-MVC-001).
 */
trait RequiresActiveStoreContext
{
    protected function resolveActiveStoreId(?int $explicitStoreId = null, ?int $tenantId = null): int
    {
        $tenantContext = app(TenantContext::class);
        $resolvedTenantId = $tenantId ?? $tenantContext->getTenantId() ?? (auth()->user()?->tenant_id ? (int) auth()->user()->tenant_id : null);

        $candidates = array_filter([
            $explicitStoreId,
            $tenantContext->getStoreId(),
            is_numeric(session('active_store_id')) ? (int) session('active_store_id') : null,
            auth()->user()?->store_id ? (int) auth()->user()->store_id : null,
        ], static fn ($id) => is_int($id) && $id > 0);

        foreach ($candidates as $candidateId) {
            if ($resolvedTenantId !== null) {
                $existsInTenant = Store::withoutGlobalScopes()
                    ->where('id', $candidateId)
                    ->where('tenant_id', $resolvedTenantId)
                    ->exists();
                if ($existsInTenant) {
                    return $candidateId;
                }
            } else {
                return $candidateId;
            }
        }

        $allowedStoreIds = $tenantContext->getAllowedStoreIds();
        if (! empty($allowedStoreIds)) {
            return (int) reset($allowedStoreIds);
        }

        if ($resolvedTenantId !== null) {
            $fallbackTenantStoreId = Store::withoutGlobalScopes()
                ->where('tenant_id', $resolvedTenantId)
                ->value('id');

            if ($fallbackTenantStoreId) {
                return (int) $fallbackTenantStoreId;
            }
        }

        throw new DomainException('Konteks cabang (Store) aktif tidak ditemukan. Silakan pilih cabang terlebih dahulu.');
    }

    protected function resolveActiveDeviceId(
        ?int $explicitDeviceId = null,
        ?int $shiftOrOrderDeviceId = null,
        ?int $storeId = null,
        ?int $tenantId = null
    ): int {
        $tenantContext = app(TenantContext::class);
        $resolvedTenantId = $tenantId ?? $tenantContext->getTenantId() ?? (auth()->user()?->tenant_id ? (int) auth()->user()->tenant_id : null);
        $resolvedStoreId = $storeId ?? $tenantContext->getStoreId();

        $candidates = array_filter([
            $explicitDeviceId ? (int) $explicitDeviceId : null,
            $shiftOrOrderDeviceId ? (int) $shiftOrOrderDeviceId : null,
            is_numeric(session('active_device_id')) ? (int) session('active_device_id') : null,
        ], static fn ($id) => is_int($id) && $id > 0);

        foreach ($candidates as $candidateId) {
            if ($resolvedTenantId !== null) {
                $existsInTenant = Device::withoutGlobalScopes()
                    ->where('id', $candidateId)
                    ->where('tenant_id', $resolvedTenantId)
                    ->exists();
                if ($existsInTenant) {
                    return $candidateId;
                }
            } else {
                return $candidateId;
            }
        }

        if ($resolvedTenantId !== null) {
            $deviceQuery = Device::withoutGlobalScopes()
                ->where('tenant_id', $resolvedTenantId)
                ->where('is_active', true);

            if ($resolvedStoreId !== null && $resolvedStoreId > 0) {
                $storeDeviceId = (clone $deviceQuery)->where('store_id', $resolvedStoreId)->value('id');
                if ($storeDeviceId) {
                    return (int) $storeDeviceId;
                }
            }

            $anyTenantDeviceId = $deviceQuery->value('id');
            if ($anyTenantDeviceId) {
                return (int) $anyTenantDeviceId;
            }
        }

        throw new DomainException('Perangkat kasir (Device) aktif tidak ditemukan pada cabang ini.');
    }
}
