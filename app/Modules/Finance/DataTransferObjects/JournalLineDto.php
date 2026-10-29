<?php

declare(strict_types=1);

namespace App\Modules\Finance\DataTransferObjects;

readonly class JournalLineDto
{
    public function __construct(
        public int $accountId,
        public string $debitAmount = '0.00',
        public string $creditAmount = '0.00',
        public ?string $description = null,
        public ?int $storeId = null,
        public ?int $customerId = null,
        public ?int $supplierId = null,
        public ?int $taxCodeId = null
    ) {}
}
