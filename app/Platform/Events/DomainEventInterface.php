<?php

declare(strict_types=1);

namespace App\Platform\Events;

/**
 * Canonical contract for immutable, versioned domain events (STR-13, ADR-107).
 * All domain events MUST carry scalar/DTO payloads only, with ZERO Eloquent models.
 */
interface DomainEventInterface
{
    /**
     * Canonical event name following <module>.<aggregate>.<past_tense>.v<major>.
     */
    public function eventName(): string;

    /**
     * Integer schema version for schema evolution and backwards compatibility.
     */
    public function schemaVersion(): int;

    /**
     * Tenant ID scoping boundary for fail-closed multi-tenant event processing.
     */
    public function tenantId(): int;

    /**
     * ISO-8601 timestamp string representing when the domain event occurred.
     */
    public function occurredAt(): string;

    /**
     * Pure array payload containing only scalars/primitives/DTO representations.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array;
}
