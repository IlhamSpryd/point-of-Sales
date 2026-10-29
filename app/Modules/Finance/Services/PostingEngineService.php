<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Models\User;
use App\Modules\Finance\Contracts\PostingEngineContract;
use App\Modules\Finance\DataTransferObjects\FinancialEventDto;
use App\Modules\Finance\DataTransferObjects\JournalLineDto;
use App\Modules\Finance\DataTransferObjects\ManualJournalDto;
use App\Modules\Finance\Enums\JournalStatusEnum;
use App\Modules\Finance\Enums\JournalTypeEnum;
use App\Modules\Finance\Models\AccountingPeriod;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\FinancialEventOutbox;
use App\Modules\Finance\Models\JournalEntry;
use App\Services\Context\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;

class PostingEngineService implements PostingEngineContract
{
    public function __construct(
        protected TenantContext $tenantContext,
        protected PostingRuleResolverService $ruleResolver,
        protected AccountMappingResolverService $accountMapper,
        protected JournalEntryService $journalEntryService,
        protected AccountingPeriodService $periodService
    ) {}

    public function postEvent(FinancialEventDto $event): JournalEntry
    {
        // 1. Cek Idempotency langsung: Jika sudah diposting, kembalikan yang ada
        $existing = JournalEntry::withoutTenantScope()
            ->where('tenant_id', $event->tenantId)
            ->where('idempotency_key', $event->idempotencyKey)
            ->first();

        if ($existing) {
            return $existing;
        }

        // 2. Resolve Active Rule Version
        $ruleVersion = $this->ruleResolver->resolveActiveRuleVersion(
            $event->tenantId,
            $event->eventCode,
            $event->accountingDate
        );

        // 3. Generate Lines from Rule Version & Payload
        $lines = [];
        $totalDebit = '0.00';
        $totalCredit = '0.00';

        foreach ($ruleVersion->lines as $ruleLine) {
            $amountKey = $ruleLine->amount_source_key;
            $amount = $event->payload[$amountKey] ?? '0.00';
            $amountDec = bcadd((string) $amount, '0', 2);

            // Lewati baris 0 jika zero_amount_behavior = SKIP_LINE
            if (bccomp($amountDec, '0.00', 2) === 0) {
                if ($ruleLine->zero_amount_behavior === 'FAIL_POSTING') {
                    throw new DomainException("FIN-M7-01: Nilai untuk '{$amountKey}' tidak boleh 0.00 pada aturan posting #{$ruleVersion->posting_rule_id}.");
                }

                continue;
            }

            $accountId = $this->accountMapper->resolveAccountId(
                $ruleLine,
                $event->tenantId,
                $event->payload
            );

            $debit = $ruleLine->isDebit() ? $amountDec : '0.00';
            $credit = $ruleLine->isCredit() ? $amountDec : '0.00';

            $totalDebit = bcadd($totalDebit, $debit, 2);
            $totalCredit = bcadd($totalCredit, $credit, 2);

            $lines[] = new JournalLineDto(
                accountId: $accountId,
                debitAmount: $debit,
                creditAmount: $credit,
                description: $ruleLine->description_template ?? $event->eventCode,
                storeId: $event->storeId,
                customerId: $event->payload['customer_id'] ?? null,
                supplierId: $event->payload['supplier_id'] ?? null,
                taxCodeId: $event->payload['tax_code_id'] ?? null,
            );
        }

        // 4. Cent Residual Rounding Handling (jika ada selisih sen kecil karena prorata)
        $diff = bcsub($totalDebit, $totalCredit, 2);
        if (bccomp($diff, '0.00', 2) !== 0 && abs((float) $diff) <= 0.05 && count($lines) > 0) {
            // Alokasikan selisih sen ke baris terbesar
            $adjustedLines = [];
            $maxIndex = 0;
            $maxAmount = '0.00';

            foreach ($lines as $i => $l) {
                $cur = bccomp($l->debitAmount, '0.00', 2) > 0 ? $l->debitAmount : $l->creditAmount;
                if (bccomp($cur, $maxAmount, 2) > 0) {
                    $maxAmount = $cur;
                    $maxIndex = $i;
                }
            }

            foreach ($lines as $i => $l) {
                if ($i === $maxIndex) {
                    if (bccomp($diff, '0.00', 2) > 0) {
                        // Debit lebih besar, tambahkan credit pada baris kredit terbesar
                        $adjustedLines[] = new JournalLineDto(
                            accountId: $l->accountId,
                            debitAmount: $l->debitAmount,
                            creditAmount: bccomp($l->creditAmount, '0.00', 2) > 0 ? bcadd($l->creditAmount, $diff, 2) : $l->creditAmount,
                            description: $l->description,
                            storeId: $l->storeId,
                            customerId: $l->customerId,
                            supplierId: $l->supplierId,
                            taxCodeId: $l->taxCodeId,
                        );
                    } else {
                        // Credit lebih besar, tambahkan debit
                        $absDiff = bcmul($diff, '-1', 2);
                        $adjustedLines[] = new JournalLineDto(
                            accountId: $l->accountId,
                            debitAmount: bccomp($l->debitAmount, '0.00', 2) > 0 ? bcadd($l->debitAmount, $absDiff, 2) : $l->debitAmount,
                            creditAmount: $l->creditAmount,
                            description: $l->description,
                            storeId: $l->storeId,
                            customerId: $l->customerId,
                            supplierId: $l->supplierId,
                            taxCodeId: $l->taxCodeId,
                        );
                    }
                } else {
                    $adjustedLines[] = $l;
                }
            }
            $lines = $adjustedLines;
        }

        // 5. Delegate ke JournalEntryService untuk persistensi & balance update
        return $this->journalEntryService->createAndPostJournal(
            tenantId: $event->tenantId,
            storeId: $event->storeId,
            postingDate: $event->accountingDate,
            idempotencyKey: $event->idempotencyKey,
            lines: $lines,
            sourceModule: $event->sourceModule,
            sourceType: $event->sourceType,
            sourceId: $event->sourceId,
            sourceNumber: $event->sourceDocumentNumber,
            journalType: JournalTypeEnum::SYSTEM_AUTO,
            postingRuleVersionId: $ruleVersion->id,
            notes: "Auto-posted for {$event->eventCode} #{$event->sourceDocumentNumber}",
            currencyCode: $event->currencyCode
        );
    }

    /**
     * Memproses single record outbox finansial secara aman dan idempoten.
     */
    public function processOutboxEvent(FinancialEventOutbox $outbox): JournalEntry
    {
        if ($outbox->journal_entry_id && $outbox->status === 'PROCESSED') {
            $existing = JournalEntry::withoutTenantScope()->find($outbox->journal_entry_id);
            if ($existing) {
                return $existing;
            }
        }

        $accountingDate = $outbox->payload['accounting_date'] ?? $outbox->occurred_at;

        $eventDto = new FinancialEventDto(
            tenantId: $outbox->tenant_id,
            storeId: $outbox->store_id,
            sourceModule: $outbox->source_module,
            sourceType: $outbox->source_type,
            sourceId: (int) $outbox->source_id,
            sourceDocumentNumber: $outbox->source_number,
            eventCode: $outbox->event_type,
            idempotencyKey: $outbox->idempotency_key,
            occurredAt: $outbox->occurred_at,
            accountingDate: $accountingDate,
            payload: is_array($outbox->payload) ? $outbox->payload : json_decode((string) $outbox->payload, true) ?? []
        );

        $journal = $this->postEvent($eventDto);

        $outbox->update([
            'status' => 'PROCESSED',
            'journal_entry_id' => $journal->id,
            'processed_at' => now(),
        ]);

        return $journal;
    }

    public function postManualJournal(ManualJournalDto $dto, User $actor): JournalEntry
    {
        // Validasi: Jurnal manual dilarang memposting ke akun kontrol (AR, AP, Persediaan) kecuali tipe OPENING_BALANCE
        $isOpeningBalance = ($dto->journalType === 'OPENING_BALANCE');
        if (! $isOpeningBalance) {
            $accountIds = array_map(fn ($l) => $l->accountId, $dto->lines);
            $controlAccounts = ChartOfAccount::withoutTenantScope()
                ->where('tenant_id', $dto->tenantId)
                ->whereIn('id', $accountIds)
                ->where('allow_manual_journal', false)
                ->get();

            if ($controlAccounts->isNotEmpty()) {
                $names = $controlAccounts->pluck('account_code')->implode(', ');
                throw new DomainException("FIN-M7-07: Posting manual ke akun kontrol ({$names}) dilarang. Transaksi harus melalui subledger terkait.");
            }
        }

        $type = $isOpeningBalance ? JournalTypeEnum::OPENING_BALANCE : JournalTypeEnum::MANUAL_ADJUSTMENT;

        return $this->journalEntryService->createAndPostJournal(
            tenantId: $dto->tenantId,
            storeId: $dto->storeId,
            postingDate: $dto->postingDate,
            idempotencyKey: $dto->idempotencyKey,
            lines: $dto->lines,
            sourceModule: 'FINANCE',
            sourceType: 'MANUAL',
            journalType: $type,
            notes: $dto->notes,
            currencyCode: $dto->currencyCode,
            actor: $actor
        );
    }

    public function reverseJournal(JournalEntry $original, string $reason, User $actor, ?string $reversalDate = null): JournalEntry
    {
        $tenantId = $original->tenant_id;

        return DB::transaction(function () use ($original, $reason, $actor, $reversalDate, $tenantId) {
            $lockedOriginal = JournalEntry::withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->where('id', $original->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedOriginal->status !== JournalStatusEnum::POSTED) {
                throw new DomainException("FIN-M7-08: Hanya jurnal berstatus POSTED yang dapat di-reverse. Status saat ini: {$lockedOriginal->status->value}.");
            }

            if ($lockedOriginal->reversed_by_journal_id !== null) {
                throw new DomainException("FIN-M7-08: Jurnal {$lockedOriginal->journal_number} sudah pernah di-reverse sebelumnya.");
            }

            // Tentukan tanggal reversal: tanggal asli jika periode masih OPEN, atau hari pertama periode OPEN berikutnya sesuai DEC-20
            $targetDate = $reversalDate ?? $lockedOriginal->posting_date->toDateString();
            try {
                $this->periodService->resolveOpenPeriodForDate($targetDate, $tenantId);
            } catch (\Exception) {
                // Periode asli sudah ditutup: cari periode open terdekat setelah tanggal asli
                $openPeriod = AccountingPeriod::withoutTenantScope()
                    ->where('tenant_id', $tenantId)
                    ->where('start_date', '>', $lockedOriginal->posting_date->toDateString())
                    ->where('status', 'OPEN')
                    ->orderBy('start_date')
                    ->first();

                if (! $openPeriod) {
                    throw new DomainException('FIN-M7-08: Tidak dapat me-reverse jurnal karena periode asli telah ditutup dan tidak ada periode OPEN berikutnya.');
                }
                $targetDate = $openPeriod->start_date->toDateString();
            }

            // Buat baris jurnal pembalik simetris (debit <-> credit)
            $reversalLines = [];
            foreach ($lockedOriginal->lines as $line) {
                $reversalLines[] = new JournalLineDto(
                    accountId: $line->account_id,
                    debitAmount: $line->credit_amount, // Dibalik
                    creditAmount: $line->debit_amount, // Dibalik
                    description: 'Pembalik: '.($line->description ?? $lockedOriginal->journal_number),
                    storeId: $line->store_id,
                    customerId: $line->customer_id,
                    supplierId: $line->supplier_id,
                    taxCodeId: $line->tax_code_id
                );
            }

            $reversalIdempotencyKey = "reversal:{$tenantId}:{$lockedOriginal->id}:".uniqid();

            // Simpan jurnal reversal
            $reversalJournal = $this->journalEntryService->createAndPostJournal(
                tenantId: $tenantId,
                storeId: $lockedOriginal->store_id,
                postingDate: $targetDate,
                idempotencyKey: $reversalIdempotencyKey,
                lines: $reversalLines,
                sourceModule: $lockedOriginal->source_module,
                sourceType: $lockedOriginal->source_type,
                sourceId: $lockedOriginal->source_id,
                sourceNumber: $lockedOriginal->source_number,
                journalType: JournalTypeEnum::REVERSAL,
                reversalOfJournalId: $lockedOriginal->id,
                notes: "Reversal of {$lockedOriginal->journal_number}. Alasan: {$reason}",
                currencyCode: $lockedOriginal->currency_code,
                actor: $actor
            );

            // Update status jurnal asli menjadi REVERSED (diizinkan oleh trigger karena tidak merubah nominal finansial)
            $lockedOriginal->status = JournalStatusEnum::REVERSED;
            $lockedOriginal->reversed_by_journal_id = $reversalJournal->id;
            $lockedOriginal->reversed_at = now();
            $lockedOriginal->reversal_reason = $reason;
            $lockedOriginal->save();

            return $reversalJournal;
        });
    }
}
