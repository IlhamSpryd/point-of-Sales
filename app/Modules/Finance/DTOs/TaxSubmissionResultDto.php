<?php

declare(strict_types=1);

namespace App\Modules\Finance\DTOs;

class TaxSubmissionResultDto
{
    /**
     * @param array<string, mixed> $requestLog
     * @param array<string, mixed> $responseLog
     */
    public function __construct(
        public string $providerName,
        public string $submissionReference,
        public string $status,
        public ?string $taxInvoiceSerialNumber,
        public string $rawPayloadHash,
        public array $requestLog = [],
        public array $responseLog = [],
        public ?string $errorMessage = null,
        public ?string $submittedAt = null
    ) {}

    public function isSuccess(): bool
    {
        return in_array($this->status, ['SUCCESS', 'INTERNAL_RECORDED'], true);
    }
}
