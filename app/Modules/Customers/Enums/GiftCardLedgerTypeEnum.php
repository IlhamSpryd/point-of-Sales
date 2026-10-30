<?php

declare(strict_types=1);

namespace App\Modules\Customers\Enums;

enum GiftCardLedgerTypeEnum: string
{
    case ISSUE = 'ISSUE';
    case REDEEM = 'REDEEM';
    case REFUND_REVERSAL = 'REFUND_REVERSAL';
    case EXPIRE_WRITE_OFF = 'EXPIRE_WRITE_OFF';

    public function label(): string
    {
        return match ($this) {
            self::ISSUE => 'Initial Balance Issuance',
            self::REDEEM => 'Redemption / Payment',
            self::REFUND_REVERSAL => 'Refund / Reversal',
            self::EXPIRE_WRITE_OFF => 'Expiration Write-off',
        };
    }
}
