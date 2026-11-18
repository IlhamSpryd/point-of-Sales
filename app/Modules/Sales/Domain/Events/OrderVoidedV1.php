<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain\Events;

use App\Platform\Events\DomainEventInterface;

/**
 * Versioned domain event emitted when a sales order is voided (STR-13).
 */
final readonly class OrderVoidedV1 implements DomainEventInterface
{
    public const EVENT_NAME = 'sales.order.voided.v1';

    public const SCHEMA_VERSION = 1;

    public function __construct(
        public string $eventId,
        public int $tenantId,
        public ?int $companyId,
        public ?int $branchId,
        public int $orderId,
        public string $orderNumber,
        public string $reason,
        public ?int $actorId,
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
            'order_id' => $this->orderId,
            'order_number' => $this->orderNumber,
            'reason' => $this->reason,
            'actor_id' => $this->actorId,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
