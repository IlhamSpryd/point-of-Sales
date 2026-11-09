<?php

declare(strict_types=1);

namespace App\Core\Context;

use Closure;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class TenantContext
{
    private ?int $tenantId = null;

    private ?int $companyId = null;

    private ?int $storeId = null;

    private bool $isBypassed = false;

    /** @var array<int, int> */
    private array $allowedStoreIds = [];

    public function isBypassed(): bool
    {
        return $this->isBypassed;
    }

    /**
     * @param  array<int, int>  $storeIds
     */
    public function setAllowedStoreIds(array $storeIds): void
    {
        $this->allowedStoreIds = $storeIds;
    }

    /**
     * @return array<int, int>
     */
    public function getAllowedStoreIds(): array
    {
        return $this->allowedStoreIds;
    }

    /**
     * True hanya bila pengguna boleh melihat seluruh cabang tenant
     * (Owner). Dipakai oleh mode laporan lintas-cabang allStores().
     */
    public function isOwnerScope(): bool
    {
        return count($this->allowedStoreIds) > 1
            || (bool) (auth()->user()?->role?->is_admin ?? false)
            || auth()->user()?->role?->name === 'Owner';
    }

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

    public function setCompanyId(?int $companyId): void
    {
        $this->companyId = $companyId;
    }

    public function getCompanyId(): ?int
    {
        return $this->companyId;
    }

    public function requireCompanyId(): int
    {
        if ($this->companyId === null) {
            throw new RuntimeException('Company context has not been set for this request.');
        }

        return $this->companyId;
    }

    public function requireStoreId(): int
    {
        if ($this->storeId === null) {
            throw new RuntimeException('Store context has not been set for this request.');
        }

        return $this->storeId;
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
        $this->companyId = null;
        $this->storeId = null;
    }

    /**
     * Executes the given callback under the specified tenant (and optional store/company) context.
     * Restores the previous context afterwards.
     *
     * Bypass flag SELALU dinonaktifkan selama callback berjalan. Tanpa ini,
     * pemanggilan runAs() setelah runWithoutTenant() (mis. provisioning:
     * cek email -> buat tenant) akan mewarisi isBypassed = true sehingga
     * TenantScope tidak aktif dan isolasi tenant fail-open. Flag dipulihkan
     * ke nilai sebelumnya setelah callback selesai.
     */
    public function runAs(int $tenantId, ?int $storeId, Closure $cb, ?int $companyId = null): mixed
    {
        $prevTenantId = $this->tenantId;
        $prevCompanyId = $this->companyId;
        $prevStoreId = $this->storeId;
        $prevBypassed = $this->isBypassed;

        $this->tenantId = $tenantId;
        $this->companyId = $companyId;
        $this->storeId = $storeId;
        $this->isBypassed = false;

        try {
            return $cb();
        } finally {
            $this->tenantId = $prevTenantId;
            $this->companyId = $prevCompanyId;
            $this->storeId = $prevStoreId;
            $this->isBypassed = $prevBypassed;
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
            'previous_company_id' => $this->companyId,
            'previous_store_id' => $this->storeId,
            'trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3),
        ]);
        $prevTenantId = $this->tenantId;
        $prevCompanyId = $this->companyId;
        $prevStoreId = $this->storeId;
        $prevBypassed = $this->isBypassed;
        $this->tenantId = null;
        $this->companyId = null;
        $this->storeId = null;
        $this->isBypassed = true;
        try {
            return $cb();
        } finally {
            $this->tenantId = $prevTenantId;
            $this->companyId = $prevCompanyId;
            $this->storeId = $prevStoreId;
            $this->isBypassed = $prevBypassed;
        }
    }
}
