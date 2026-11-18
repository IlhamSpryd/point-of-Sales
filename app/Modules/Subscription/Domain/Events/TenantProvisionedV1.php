<?php

declare(strict_types=1);

namespace App\Modules\Subscription\Domain\Events;

use App\Platform\Events\DomainEventInterface;

/**
 * Versioned domain event emitted when a new tenant has been fully provisioned (STR-13).
 */
final readonly class TenantProvisionedV1 implements DomainEventInterface
{
    public const EVENT_NAME = 'tenancy.tenant.provisioned.v1';

    public const SCHEMA_VERSION = 1;

    public function __construct(
        public string $eventId,
        public int $tenantId,
        public ?int $companyId,
        public ?int $branchId,
        public string $tenantName,
        public string $planCode,
        public string $ownerEmail,
        public string $occurredAt
    ) {}

    public function eventName(): string
    {
        return self::EVENT_NAME;
    }

    public function schemaVersion(): int
    {
        return self::SCHEMA_VERSION;
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function occurredAt(): string
    {
        return $this->occurredAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'event_id' => $this->eventId,
            'event_name' => $this->eventName(),
            'schema_version' => $this->schemaVersion(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'branch_id' => $this->branchId,
            'tenant_name' => $this->tenantName,
            'plan_code' => $this->planCode,
            'owner_email' => $this->ownerEmail,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
