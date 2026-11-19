<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\OrderType;
use App\Modules\Sales\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class KdsOrderCreated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $orderId;

    public string $orderNumber;

    public int $tenantId;

    public int $storeId;

    public string $orderType;

    public ?string $tableNumber;

    public string $createdAt;

    public function __construct(Order $order)
    {
        $this->orderId = (int) $order->id;
        $this->orderNumber = (string) ($order->order_code ?? '');
        $this->tenantId = (int) ($order->tenant_id ?? 0);
        $this->storeId = (int) ($order->store_id ?? 0);
        $this->orderType = (OrderType::coerce($order->order_type) ?? OrderType::DineIn)->value;

        $table = $order->table;
        $tableNumber = $order->table_number ?? ($table ? $table->table_name : null);
        $this->tableNumber = $tableNumber !== null ? (string) $tableNumber : null;
        $this->createdAt = now()->toIso8601String();
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        if ($this->tenantId > 0 && $this->storeId > 0) {
            return [
                new PrivateChannel("kds.tenant.{$this->tenantId}.store.{$this->storeId}"),
            ];
        }

        if ($this->tenantId > 0) {
            return [
                new PrivateChannel("kds.tenant.{$this->tenantId}"),
            ];
        }

        return [];
    }

    public function broadcastAs(): string
    {
        return 'kds.order.created';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->orderId,
            'order_number' => $this->orderNumber,
            'tenant_id' => $this->tenantId,
            'store_id' => $this->storeId,
            'order_type' => $this->orderType,
            'table_number' => $this->tableNumber,
            'created_at' => $this->createdAt,
        ];
    }
}
