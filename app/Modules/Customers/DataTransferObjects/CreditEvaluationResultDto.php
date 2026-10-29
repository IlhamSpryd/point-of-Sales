<?php

declare(strict_types=1);

namespace App\Modules\Customers\DataTransferObjects;

use App\Modules\Customers\Enums\CreditEvaluationStatusEnum;

final class CreditEvaluationResultDto
{
    public function __construct(
        public readonly CreditEvaluationStatusEnum $status,
        public readonly bool $allowed,
        public readonly string $creditLimit,
        public readonly string $reservedExposure,
        public readonly string $invoicedExposure,
        public readonly string $unappliedCredit,
        public readonly string $currentTotalExposure,
        public readonly string $additionalAmount,
        public readonly string $projectedTotalExposure,
        public readonly string $availableCredit,
        public readonly bool $isOverdue = false,
        public readonly ?string $reason = null,
        public readonly array $meta = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'allowed' => $this->allowed,
            'credit_limit' => $this->creditLimit,
            'reserved_exposure' => $this->reservedExposure,
            'invoiced_exposure' => $this->invoicedExposure,
            'unapplied_credit' => $this->unappliedCredit,
            'current_total_exposure' => $this->currentTotalExposure,
            'additional_amount' => $this->additionalAmount,
            'projected_total_exposure' => $this->projectedTotalExposure,
            'available_credit' => $this->availableCredit,
            'is_overdue' => $this->isOverdue,
            'reason' => $this->reason,
            'meta' => $this->meta,
        ];
    }
}
