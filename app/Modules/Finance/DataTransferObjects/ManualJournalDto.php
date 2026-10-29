<?php

declare(strict_types=1);

namespace App\Modules\Finance\DataTransferObjects;

use Carbon\CarbonInterface;

readonly class ManualJournalDto
{
    /**
     * @param  array<JournalLineDto>  $lines
     */
    public function __construct(
        public int $tenantId,
        public ?int $storeId,
        public string|CarbonInterface $postingDate,
        public string $idempotencyKey,
        public array $lines,
        public string $notes = '',
        public string $currencyCode = 'IDR',
        public string $journalType = 'MANUAL_ADJUSTMENT' // MANUAL_ADJUSTMENT or OPENING_BALANCE
    ) {}
}
