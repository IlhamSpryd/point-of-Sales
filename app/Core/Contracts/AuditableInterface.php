<?php

declare(strict_types=1);

namespace App\Core\Contracts;

/**
 * Interface for domain entities tracking immutable audit trail logs.
 */
interface AuditableInterface
{
    public function getAuditIdentifier(): string|int;

    public function getAuditPayload(): array;
}
