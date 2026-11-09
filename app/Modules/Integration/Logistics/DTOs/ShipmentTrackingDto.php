<?php

declare(strict_types=1);

namespace App\Modules\Integration\Logistics\DTOs;

use App\Enums\ShippingDispatchStatus;

class ShipmentTrackingDto
{
    /**
     * @param list<array{timestamp: string, status: string, location: string, note: string}> $history
     */
    public function __construct(
        public string $courierCode,
        public string $waybillNumber,
        public ShippingDispatchStatus $currentStatus,
        public string $statusDescription,
        public ?string $senderName = null,
        public ?string $recipientName = null,
        public ?string $receivedBy = null,
        public ?string $deliveredAt = null,
        public array $history = [],
        public array $rawTrackingData = []
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'courier_code' => $this->courierCode,
            'waybill_number' => $this->waybillNumber,
            'current_status' => $this->currentStatus->value,
            'status_label' => $this->currentStatus->label(),
            'status_description' => $this->statusDescription,
            'sender_name' => $this->senderName,
            'recipient_name' => $this->recipientName,
            'received_by' => $this->receivedBy,
            'delivered_at' => $this->deliveredAt,
            'history' => $this->history,
        ];
    }
}
