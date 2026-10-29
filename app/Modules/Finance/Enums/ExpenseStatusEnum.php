<?php

declare(strict_types=1);

namespace App\Modules\Finance\Enums;

enum ExpenseStatusEnum: string
{
    case DRAFT = 'DRAFT';
    case SUBMITTED = 'SUBMITTED';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
    case PAID = 'PAID';
    case VOIDED = 'VOIDED';

    public function isFinal(): bool
    {
        return in_array($this, [self::PAID, self::VOIDED, self::REJECTED], true);
    }
}
