<?php

declare(strict_types=1);

namespace App\Modules\Finance\Enums;

enum JournalStatusEnum: string
{
    case DRAFT = 'DRAFT';
    case PENDING_APPROVAL = 'PENDING_APPROVAL';
    case POSTED = 'POSTED';
    case REVERSED = 'REVERSED';
    case VOIDED_DRAFT = 'VOIDED_DRAFT';

    public function isImmutable(): bool
    {
        return in_array($this, [self::POSTED, self::REVERSED], true);
    }
}
