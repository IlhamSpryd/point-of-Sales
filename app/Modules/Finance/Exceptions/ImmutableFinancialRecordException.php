<?php

declare(strict_types=1);

namespace App\Modules\Finance\Exceptions;

use RuntimeException;

class ImmutableFinancialRecordException extends RuntimeException
{
    public static function forJournal(string $journalNumber, string $status): self
    {
        return new self("FIN-M7-08: Jurnal {$journalNumber} berstatus {$status} bersifat imutabel (tidak dapat diedit atau dihapus). Gunakan prosedur pembalikan (reversal).");
    }

    public static function forLine(int $lineId): self
    {
        return new self("FIN-M7-08: Baris jurnal #{$lineId} milik jurnal yang sudah POSTED tidak dapat dimodifikasi.");
    }
}
