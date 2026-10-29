<?php

declare(strict_types=1);

namespace App\Modules\Finance\Enums;

enum OutboxStatusEnum: string
{
    case PENDING = 'PENDING';
    case PROCESSING = 'PROCESSING';
    case PROCESSED = 'PROCESSED';
    case FAILED = 'FAILED';
    case DEAD_LETTER = 'DEAD_LETTER';
}
