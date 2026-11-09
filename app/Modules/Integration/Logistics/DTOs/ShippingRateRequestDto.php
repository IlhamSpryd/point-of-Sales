<?php

declare(strict_types=1);

namespace App\Modules\Integration\Logistics\DTOs;

class ShippingRateRequestDto
{
    /**
     * @param array{length?: int, width?: int, height?: int}|null $dimensionsCm
     */
    public function __construct(
        public string $originPostalCode,
        public string $originCity,
        public string $destinationPostalCode,
        public string $destinationCity,
        public int $weightGrams,
        public ?array $dimensionsCm = null,
        public ?string $itemsValue = null,
        public bool $isCod = false,
        public array $metadata = []
    ) {}

    /**
     * Calculate volumetric weight in grams: (L x W x H) / 6000 * 1000.
     */
    public function getChargeableWeightGrams(): int
    {
        if ($this->dimensionsCm !== null &&
            isset($this->dimensionsCm['length'], $this->dimensionsCm['width'], $this->dimensionsCm['height']) &&
            $this->dimensionsCm['length'] > 0 &&
            $this->dimensionsCm['width'] > 0 &&
            $this->dimensionsCm['height'] > 0
        ) {
            $volumeCm3 = $this->dimensionsCm['length'] * $this->dimensionsCm['width'] * $this->dimensionsCm['height'];
            $volumetricGrams = (int) round(($volumeCm3 / 6000.0) * 1000.0);

            return max($this->weightGrams, $volumetricGrams);
        }

        return max(1000, $this->weightGrams); // Minimum 1 kg (1000g) chargeable weight
    }

    public function getWeightKg(): float
    {
        return ceil($this->getChargeableWeightGrams() / 1000.0);
    }
}
