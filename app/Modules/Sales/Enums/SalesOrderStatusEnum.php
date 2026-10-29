<?php

declare(strict_types=1);

namespace App\Modules\Sales\Enums;

enum SalesOrderStatusEnum: string
{
    case DRAFT = 'DRAFT';
    case PENDING_CREDIT_APPROVAL = 'PENDING_CREDIT_APPROVAL';
    case CONFIRMED = 'CONFIRMED';
    case PARTIALLY_FULFILLED = 'PARTIALLY_FULFILLED';
    case FULFILLED = 'FULFILLED';
    case CLOSED = 'CLOSED';
    case CANCELLED = 'CANCELLED';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::CLOSED,
            self::CANCELLED,
        ], true);
    }

    public function canBeCancelled(): bool
    {
        return in_array($this, [
            self::DRAFT,
            self::PENDING_CREDIT_APPROVAL,
            self::CONFIRMED,
        ], true);
    }
}
