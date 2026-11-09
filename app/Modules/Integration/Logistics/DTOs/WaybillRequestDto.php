<?php

declare(strict_types=1);

namespace App\Modules\Integration\Logistics\DTOs;

class WaybillRequestDto
{
    /**
     * @param array{
     *     name: string,
     *     phone: string,
     *     address: string,
     *     postal_code: string,
     *     city: string,
     *     province?: string,
     * } $sender
     * @param array{
     *     name: string,
     *     phone: string,
     *     address: string,
     *     postal_code: string,
     *     city: string,
     *     province?: string,
     * } $recipient
     */
    public function __construct(
        public int $tenantId,
        public ?int $orderId,
        public string $courierCode,
        public string $serviceCode,
        public array $sender,
        public array $recipient,
        public int $weightGrams,
        public string $goodsDescription,
        public string $itemValue,
        public bool $isCod = false,
        public ?string $codAmount = null,
        public ?string $notes = null,
        public array $metadata = []
    ) {}
}
