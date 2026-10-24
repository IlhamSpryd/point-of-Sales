<?php

declare(strict_types=1);

namespace App\Core\Contracts;

/**
 * Interface for domain entities scoped to a specific store branch.
 */
interface StoreScopedInterface
{
    public function getStoreId(): ?int;
}
