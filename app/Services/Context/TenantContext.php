<?php

namespace App\Services\Context;

use Closure;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class TenantContext
{
    private ?int $tenantId = null;

    private ?int $storeId = null;

    public function setTenantId(int $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    public function getTenantId(): ?int
    {
        return $this->tenantId;
    }

    public function requireTenantId(): int
    {
        if ($this->tenantId === null) {
            throw new RuntimeException('Tenant context has not been set for this request.');
        }

        return $this->tenantId;
    }

    public function setStoreId(?int $storeId): void
    {
        $this->storeId = $storeId;
    }

    public function getStoreId(): ?int
    {
        return $this->storeId;
    }

    public function hasTenant(): bool
    {
        return $this->tenantId !== null;
    }

    public function clear(): void
    {
        $this->tenantId = null;
        $this->storeId = null;
    }

    /**
     * Executes the given callback under the specified tenant (and optional store) context.
     * Restores the previous context afterwards.
     */
    public function runAs(int $tenantId, ?int $storeId, Closure $cb): mixed
    {
        $prevTenantId = $this->tenantId;
        $prevStoreId = $this->storeId;

        $this->tenantId = $tenantId;
        $this->storeId = $storeId;

        try {
            return $cb();
        } finally {
            $this->tenantId = $prevTenantId;
            $this->storeId = $prevStoreId;
        }
    }

    /**
     * Executes the given callback outside of any tenant context.
     * Restores the previous context afterwards.
     * Acts as an explicit, logged escape hatch.
     */
    public function runWithoutTenant(Closure $cb): mixed
    {
        Log::warning('Executing operation without tenant context.', [
            'previous_tenant_id' => $this->tenantId,
            'previous_store_id' => $this->storeId,
            'trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3),
        ]);

        $prevTenantId = $this->tenantId;
        $prevStoreId = $this->storeId;

        $this->tenantId = null;
        $this->storeId = null;

        try {
            return $cb();
        } finally {
            $this->tenantId = $prevTenantId;
            $this->storeId = $prevStoreId;
        }
    }
}
