<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\OrderItem;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class KdsItemUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $orderItemId;

    public int $orderId;

    public string $orderNumber;

    public string $preparationStatus;

    public ?string $station;

    public ?int $processedBy;

    public int $tenantId;

    public int $storeId;

    public string $action;

    public string $updatedAt;

    public function __construct(
        OrderItem $item,
        string $action = 'updated'
    ) {
        $item->loadMissing(['order', 'product.category']);

        $order = $item->order;
        $this->orderItemId = (int) $item->id;
        $this->orderId = (int) $item->order_id;
        $this->orderNumber = (string) ($order->order_code ?? '');

        $this->preparationStatus = $item->preparation_status->value;

        $stationEnum = $item->product?->kitchen_station;
        if (! $stationEnum || $stationEnum === \App\Enums\KitchenStation::None) {
            $stationEnum = $item->product?->category?->kitchen_station;
        }

        $this->station = $stationEnum ? $stationEnum->value : 'kitchen';

        $this->processedBy = $item->processed_by !== null ? (int) $item->processed_by : null;
        $this->tenantId = (int) ($item->tenant_id ?? ($order ? $order->tenant_id : 0));
        $this->storeId = (int) ($order ? $order->store_id : 0);
        $this->action = $action;
        $this->updatedAt = now()->toIso8601String();
    }

    /**
     * Channel scoped per DEC-001 (tenant and store boundary).
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        if ($this->tenantId > 0 && $this->storeId > 0) {
            return [
                new PrivateChannel("tenant.{$this->tenantId}.store.{$this->storeId}.kds"),
            ];
        }

        if ($this->tenantId > 0) {
            return [
                new PrivateChannel("tenant.{$this->tenantId}.kds"),
            ];
        }

        return [];
    }

    public function broadcastAs(): string
    {
        return 'kds.item.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'item_id' => $this->orderItemId,
            'order_id' => $this->orderId,
            'order_number' => $this->orderNumber,
            'preparation_status' => $this->preparationStatus,
            'station' => $this->station,
            'processed_by' => $this->processedBy,
            'tenant_id' => $this->tenantId,
            'store_id' => $this->storeId,
            'action' => $this->action,
            'updated_at' => $this->updatedAt,
        ];
    }
}
