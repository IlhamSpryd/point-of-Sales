<?php

declare(strict_types=1);

namespace App\Modules\HR\DTOs;

use App\Modules\HR\Enums\SalaryComponentTypeEnum;

readonly class PayrollCalculationItemDto
{
    public function __construct(
        public string $componentCode,
        public string $componentName,
        public SalaryComponentTypeEnum $type,
        public float $amount,
        public bool $isTaxable = true,
        public ?int $statutoryRuleVersionId = null,
        public ?string $notes = null
    ) {}
}
