<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WaiterCalled implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $tableId;

    public string $tableName;

    public ?int $tenantId;

    public ?int $storeId;

    /**
     * Create a new event instance.
     */
    public function __construct(int $tableId, string $tableName, ?int $tenantId = null, ?int $storeId = null)
    {
        $this->tableId = $tableId;
        $this->tableName = $tableName;
        $this->tenantId = $tenantId;
        $this->storeId = $storeId;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        if ($this->tenantId && $this->storeId) {
            return [
                new \Illuminate\Broadcasting\PrivateChannel("tenant.{$this->tenantId}.store.{$this->storeId}.waiter-calls"),
            ];
        }

        return [
            new \Illuminate\Broadcasting\PrivateChannel('waiter-calls'),
        ];
    }
}
