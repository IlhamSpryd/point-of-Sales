<?php

declare(strict_types=1);

namespace App\Modules\Finance\Enums;

enum JournalTypeEnum: string
{
    case SYSTEM_AUTO = 'SYSTEM_AUTO';
    case MANUAL_ADJUSTMENT = 'MANUAL_ADJUSTMENT';
    case REVERSAL = 'REVERSAL';
    case OPENING_BALANCE = 'OPENING_BALANCE';
    case CLOSING_ENTRY = 'CLOSING_ENTRY';
    case DEPRECIATION = 'DEPRECIATION';
}
