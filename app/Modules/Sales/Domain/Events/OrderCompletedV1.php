<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain\Events;

use App\Platform\Events\DomainEventInterface;

/**
 * Versioned domain event emitted when a sales order is completed (STR-13).
 */
final readonly class OrderCompletedV1 implements DomainEventInterface
{
    public const EVENT_NAME = 'sales.order.completed.v1';

    public const SCHEMA_VERSION = 1;

    /**
     * @param  array<int, array{product_id: int, product_name: string, quantity: int|float, unit_price: string, subtotal: string}>  $lines
     */
    public function __construct(
        public string $eventId,
        public int $tenantId,
        public ?int $companyId,
        public ?int $branchId,
        public int $orderId,
        public string $orderNumber,
        public string $totalAmount,
        public string $paymentMethod,
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
            'order_id' => $this->orderId,
            'order_number' => $this->orderNumber,
            'total_amount' => $this->totalAmount,
            'payment_method' => $this->paymentMethod,
            'lines' => $this->lines,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
