<?php

declare(strict_types=1);

namespace App\Modules\Finance\Exceptions;

use RuntimeException;

class ClosedAccountingPeriodException extends RuntimeException
{
    public static function forPeriod(string $periodCode, string $status): self
    {
        return new self("FIN-M7-08: Periode akuntansi {$periodCode} berstatus {$status}. Posting transaksi baru ke periode tertutup dilarang.");
    }

    public static function forDate(string $date): self
    {
        return new self("FIN-M7-08: Tidak ditemukan periode akuntansi berstatus OPEN untuk tanggal transaksi {$date}.");
    }
}
