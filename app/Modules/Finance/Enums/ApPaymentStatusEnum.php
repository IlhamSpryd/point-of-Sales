<?php

declare(strict_types=1);

namespace App\Modules\Finance\Enums;

enum ApPaymentStatusEnum: string
{
    case DRAFT = 'DRAFT';
    case PENDING_APPROVAL = 'PENDING_APPROVAL';
    case POSTED = 'POSTED';
    case VOIDED = 'VOIDED';
}
