<?php
declare(strict_types=1);

namespace App\Enums;

enum LoyaltyLedgerTypeEnum: string
{
    case Earn = 'earn';
    case Redeem = 'redeem';
    case Expire = 'expire';
    case Adjustment = 'adjustment';
    case Bonus = 'bonus';
    case Reversal = 'reversal';
}
