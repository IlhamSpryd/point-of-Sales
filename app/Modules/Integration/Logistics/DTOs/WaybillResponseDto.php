<?php

declare(strict_types=1);

namespace App\Modules\Integration\Logistics\DTOs;

class WaybillResponseDto
{
    public function __construct(
        public string $courierCode,
        public string $waybillNumber,
        public string $bookingId,
        public string $status,
        public string $serviceCode,
        public string $shippingFee,
        public ?string $labelUrl = null,
        public ?string $estimatedDeliveryDate = null,
        public ?string $createdAt = null,
        public array $rawResponse = []
    ) {
        $this->createdAt = $this->createdAt ?? now()->toIso8601String();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'courier_code' => $this->courierCode,
            'waybill_number' => $this->waybillNumber,
            'booking_id' => $this->bookingId,
            'status' => $this->status,
            'service_code' => $this->serviceCode,
            'shipping_fee' => $this->shippingFee,
            'label_url' => $this->labelUrl,
            'estimated_delivery_date' => $this->estimatedDeliveryDate,
            'created_at' => $this->createdAt,
            'raw_response' => $this->rawResponse,
        ];
    }
}
