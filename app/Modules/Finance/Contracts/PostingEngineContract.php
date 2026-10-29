<?php

declare(strict_types=1);

namespace App\Modules\Finance\Contracts;

use App\Models\User;
use App\Modules\Finance\DataTransferObjects\FinancialEventDto;
use App\Modules\Finance\DataTransferObjects\ManualJournalDto;
use App\Modules\Finance\Models\JournalEntry;

interface PostingEngineContract
{
    /**
     * Memposting peristiwa finansial otomatis dari modul ERP ke buku besar umum (General Ledger).
     */
    public function postEvent(FinancialEventDto $event): JournalEntry;

    /**
     * Memposting jurnal penyesuaian manual yang telah divalidasi dan disetujui.
     */
    public function postManualJournal(ManualJournalDto $dto, User $actor): JournalEntry;

    /**
     * Membalik (reversal) jurnal yang telah diposting dengan jurnal pembalik simetris.
     */
    public function reverseJournal(JournalEntry $original, string $reason, User $actor, ?string $reversalDate = null): JournalEntry;
}
