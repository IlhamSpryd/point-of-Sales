<?php

declare(strict_types=1);

namespace App\Modules\Sales\Enums;

enum SalesOrderFulfillmentStatusEnum: string
{
    case UNFULFILLED = 'UNFULFILLED';
    case PARTIALLY_FULFILLED = 'PARTIALLY_FULFILLED';
    case FULFILLED = 'FULFILLED';
    case BACKORDERED = 'BACKORDERED';
}
