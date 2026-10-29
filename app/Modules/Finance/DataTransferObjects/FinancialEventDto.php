<?php

declare(strict_types=1);

namespace App\Modules\Finance\DataTransferObjects;

use Carbon\CarbonInterface;

readonly class FinancialEventDto
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public int $tenantId,
        public ?int $storeId,
        public string $sourceModule,
        public string $sourceType,
        public int $sourceId,
        public ?string $sourceDocumentNumber,
        public string $eventCode,
        public string $idempotencyKey,
        public string|CarbonInterface $occurredAt,
        public string|CarbonInterface $accountingDate,
        public array $payload,
        public string $currencyCode = 'IDR',
        public ?int $userId = null
    ) {}
}
