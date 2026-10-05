<?php

namespace App\Services\Context;

use RuntimeException;

class TenantContext
{
    private ?int $tenantId = null;

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

    public function hasTenant(): bool
    {
        return $this->tenantId !== null;
    }

    public function clear(): void
    {
        $this->tenantId = null;
    }
}
