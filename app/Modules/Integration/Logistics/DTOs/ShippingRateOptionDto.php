<?php

declare(strict_types=1);

namespace App\Modules\Integration\Logistics\DTOs;

class ShippingRateOptionDto
{
    public function __construct(
        public string $courierCode,
        public string $courierName,
        public string $serviceCode,
        public string $serviceName,
        public string $cost,
        public string $estimatedDays,
        public string $insuranceFee = '0.00',
        public ?string $totalCost = null,
        public ?string $description = null,
        public array $features = []
    ) {
        $this->totalCost = $totalCost ?? bcadd($this->cost, $this->insuranceFee, 2);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'courier_code' => $this->courierCode,
            'courier_name' => $this->courierName,
            'service_code' => $this->serviceCode,
            'service_name' => $this->serviceName,
            'cost' => $this->cost,
            'insurance_fee' => $this->insuranceFee,
            'total_cost' => $this->totalCost,
            'estimated_days' => $this->estimatedDays,
            'description' => $this->description,
            'features' => $this->features,
        ];
    }
}
