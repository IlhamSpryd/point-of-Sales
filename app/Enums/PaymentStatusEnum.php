<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentStatusEnum: string
{
    case Pending = 'pending';
    case Captured = 'captured';
    case Failed = 'failed';
    case Refunded = 'refunded';
}
