<?php

declare(strict_types=1);

namespace App\Modules\Customers\Enums;

enum StoreCreditLedgerTypeEnum: string
{
    case CREDIT_NOTE_ISSUE = 'CREDIT_NOTE_ISSUE';
    case OVERPAYMENT_ISSUE = 'OVERPAYMENT_ISSUE';
    case REDEEM_ORDER = 'REDEEM_ORDER';
    case REDEEM_INVOICE = 'REDEEM_INVOICE';
    case REVERSAL = 'REVERSAL';
    case EXPIRE = 'EXPIRE';

    public function label(): string
    {
        return match ($this) {
            self::CREDIT_NOTE_ISSUE => 'Penerbitan Nota Kredit (Retur)',
            self::OVERPAYMENT_ISSUE => 'Kelebihan Bayar Invoice',
            self::REDEEM_ORDER => 'Pembayaran Pesanan POS / Toko',
            self::REDEEM_INVOICE => 'Pelunasan Faktur Piutang (AR)',
            self::REVERSAL => 'Reversal Pembatalan Transaksi',
            self::EXPIRE => 'Kedaluwarsa Saldo',
        };
    }
}
