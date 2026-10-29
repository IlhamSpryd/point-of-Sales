<?php

declare(strict_types=1);

namespace App\Modules\Finance\Enums;

enum AccountingPeriodStatusEnum: string
{
    case OPEN = 'OPEN';
    case SOFT_CLOSED = 'SOFT_CLOSED';
    case CLOSED = 'CLOSED';
    case LOCKED = 'LOCKED';

    public function isOpen(): bool
    {
        return $this === self::OPEN;
    }

    public function isPostingAllowed(): bool
    {
        return in_array($this, [self::OPEN, self::SOFT_CLOSED], true);
    }
}
