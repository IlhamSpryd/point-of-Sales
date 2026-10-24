<?php

declare(strict_types=1);

namespace App\Core\Contracts;

/**
 * Interface for domain entities scoped to a specific tenant.
 */
interface TenantScopedInterface
{
    public function getTenantId(): ?int;
}
