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
            self::ISSUE => 'Penerbitan Saldo Awal',
            self::REDEEM => 'Penukaran / Pembayaran',
            self::REFUND_REVERSAL => 'Pengembalian Dana / Reversal',
            self::EXPIRE_WRITE_OFF => 'Penghapusan Kedaluwarsa',
        };
    }
}
