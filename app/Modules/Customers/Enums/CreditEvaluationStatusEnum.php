<?php

declare(strict_types=1);

namespace App\Modules\Customers\Enums;

enum CreditEvaluationStatusEnum: string
{
    case ALLOWED = 'ALLOWED';
    case SOFT_WARNING = 'SOFT_WARNING';
    case HARD_BLOCKED = 'HARD_BLOCKED';

    public function isBlocked(): bool
    {
        return $this === self::HARD_BLOCKED;
    }
}
