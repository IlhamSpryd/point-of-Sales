<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Domain\Events;

use App\Platform\Events\DomainEventInterface;

/**
 * Versioned domain event emitted when goods are received on a purchase order (STR-13).
 */
final readonly class GoodsReceivedV1 implements DomainEventInterface
{
    public const EVENT_NAME = 'purchasing.goods.received.v1';

    public const SCHEMA_VERSION = 1;

    /**
     * @param  array<int, array{product_id: int, quantity_received: int|float, unit_cost: string}>  $lines
     */
    public function __construct(
        public string $eventId,
        public int $tenantId,
        public ?int $companyId,
        public ?int $branchId,
        public int $grnId,
        public string $grnNumber,
        public ?int $purchaseOrderId,
        public array $lines,
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
            'grn_id' => $this->grnId,
            'grn_number' => $this->grnNumber,
            'purchase_order_id' => $this->purchaseOrderId,
            'lines' => $this->lines,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
