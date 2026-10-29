<?php

declare(strict_types=1);

namespace App\Modules\Sales\Enums;

enum SalesOrderInvoicingStatusEnum: string
{
    case UNINVOICED = 'UNINVOICED';
    case PARTIALLY_INVOICED = 'PARTIALLY_INVOICED';
    case FULLY_INVOICED = 'FULLY_INVOICED';
}
