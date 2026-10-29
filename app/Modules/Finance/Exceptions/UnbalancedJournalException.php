<?php

declare(strict_types=1);

namespace App\Modules\Finance\Exceptions;

use RuntimeException;

class UnbalancedJournalException extends RuntimeException
{
    public static function withAmounts(string $totalDebit, string $totalCredit, string $difference): self
    {
        return new self("FIN-M7-01: Jurnal tidak seimbang. Total Debit: {$totalDebit}, Total Credit: {$totalCredit}, Selisih: {$difference}. Debit dan Credit wajib bernilai sama.");
    }
}
