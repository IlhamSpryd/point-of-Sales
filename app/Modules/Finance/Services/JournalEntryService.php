<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Models\User;
use App\Modules\Finance\DataTransferObjects\JournalLineDto;
use App\Modules\Finance\DataTransferObjects\ManualJournalDto;
use App\Modules\Finance\Enums\JournalStatusEnum;
use App\Modules\Finance\Enums\JournalTypeEnum;
use App\Modules\Finance\Enums\NormalBalanceEnum;
use App\Modules\Finance\Exceptions\UnbalancedJournalException;
use App\Modules\Finance\Models\AccountPeriodBalance;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Purchasing\Services\DocumentSequenceService;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

class JournalEntryService
{
    public function __construct(
        protected TenantContext $tenantContext,
        protected AccountingPeriodService $periodService,
        protected DocumentSequenceService $sequenceService
    ) {}

    /**
     * Memposting kumpulan baris jurnal ke buku besar umum secara atomik dan konkuren-aman.
     *
     * @param  array<JournalLineDto>  $lines
     */
    public function createAndPostJournal(
        int $tenantId,
        ?int $storeId,
        string|CarbonInterface $postingDate,
        string $idempotencyKey,
        array $lines,
        string $sourceModule = 'FINANCE',
        string $sourceType = 'MANUAL',
        ?int $sourceId = null,
        ?string $sourceNumber = null,
        JournalTypeEnum $journalType = JournalTypeEnum::SYSTEM_AUTO,
        ?int $postingRuleVersionId = null,
        ?int $reversalOfJournalId = null,
        string $notes = '',
        string $currencyCode = 'IDR',
        ?User $actor = null
    ): JournalEntry {
        // 1. Cek Idempotency: Jika idempotency_key sudah diposting, kembalikan record existing (no-op idempoten)
        $existing = JournalEntry::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            return $existing;
        }

        if (count($lines) < 2) {
            throw new DomainException('FIN-M7-01: Jurnal akuntansi wajib memiliki minimal 2 baris (1 Debit dan 1 Credit).');
        }

        $dateStr = $postingDate instanceof CarbonInterface ? $postingDate->toDateString() : Carbon::parse($postingDate)->toDateString();

        // 2. Resolve & Validasi Periode Akuntansi (Wajib OPEN / SOFT_CLOSED, kecuali CLOSING_ENTRY pada penutupan akhir tahun)
        $period = ($journalType === JournalTypeEnum::CLOSING_ENTRY)
            ? $this->periodService->resolvePeriodForDate($dateStr, $tenantId)
            : $this->periodService->resolveOpenPeriodForDate($dateStr, $tenantId);

        // 3. Validasi Invarian Baris: Debit >= 0, Credit >= 0, Single-Sided, dan Akun Aktif Postable
        $totalDebit = '0.00';
        $totalCredit = '0.00';
        $accountIds = [];

        foreach ($lines as $index => $line) {
            $debit = bcadd((string) $line->debitAmount, '0', 2);
            $credit = bcadd((string) $line->creditAmount, '0', 2);

            if (bccomp($debit, '0.00', 2) < 0 || bccomp($credit, '0.00', 2) < 0) {
                throw new DomainException("FIN-M7-03: Baris jurnal #{$index} memiliki nominal negatif. Nilai debit dan credit wajib >= 0.00.");
            }

            $isDebit = bccomp($debit, '0.00', 2) > 0;
            $isCredit = bccomp($credit, '0.00', 2) > 0;

            if (($isDebit && $isCredit) || (! $isDebit && ! $isCredit)) {
                throw new DomainException("FIN-M7-02: Baris jurnal #{$index} wajib single-sided (hanya boleh memiliki nilai pada salah satu sisi Debit atau Credit).");
            }

            $totalDebit = bcadd($totalDebit, $debit, 2);
            $totalCredit = bcadd($totalCredit, $credit, 2);
            $accountIds[] = $line->accountId;
        }

        // 4. Validasi Invarian Keseimbangan FIN-M7-01: Sum(Debit) === Sum(Credit) > 0
        if (bccomp($totalDebit, $totalCredit, 2) !== 0 || bccomp($totalDebit, '0.00', 2) === 0) {
            $diff = bcsub($totalDebit, $totalCredit, 2);
            throw UnbalancedJournalException::withAmounts($totalDebit, $totalCredit, $diff);
        }

        // 5. Validasi Akun Aktif & Postable
        $uniqueAccountIds = array_unique($accountIds);
        $accounts = ChartOfAccount::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $uniqueAccountIds)
            ->get()
            ->keyBy('id');

        foreach ($uniqueAccountIds as $accId) {
            if (! isset($accounts[$accId])) {
                throw new DomainException("FIN-M7-07: Akun #{$accId} tidak ditemukan pada tenant #{$tenantId}.");
            }
            $acc = $accounts[$accId];
            if (! $acc->is_active) {
                throw new DomainException("FIN-M7-07: Akun '{$acc->account_code} - {$acc->account_name}' tidak aktif.");
            }
            if (! $acc->is_postable) {
                throw new DomainException("FIN-M7-07: Akun '{$acc->account_code} - {$acc->account_name}' adalah akun induk summary (is_postable = false) dan dilarang menerima posting jurnal langsung.");
            }
        }

        // 6. DB Transaction dengan Deterministic Lock Acquisition Order
        return DB::transaction(function () use (
            $tenantId, $storeId, $period, $dateStr, $idempotencyKey, $lines,
            $sourceModule, $sourceType, $sourceId, $sourceNumber, $journalType,
            $postingRuleVersionId, $reversalOfJournalId, $notes, $currencyCode,
            $actor, $totalDebit, $totalCredit, $uniqueAccountIds, $accounts
        ) {
            // Urutan Kunci Kanonis: ORDER BY account_id ASC
            sort($uniqueAccountIds, SORT_NUMERIC);

            foreach ($uniqueAccountIds as $accId) {
                $scopeKey = $storeId ?? 0;
                AccountPeriodBalance::withoutTenantScope()
                    ->where('tenant_id', $tenantId)
                    ->where('accounting_period_id', $period->id)
                    ->where('account_id', $accId)
                    ->where('store_scope_key', $scopeKey)
                    ->lockForUpdate()
                    ->first();
            }

            // Alokasikan nomor dokumen jurnal
            $prefix = ($journalType === JournalTypeEnum::REVERSAL) ? 'REV' : 'JE';
            $journalNumber = $this->sequenceService->nextNumber($tenantId, $prefix, $storeId);

            // Insert Header Jurnal
            $journal = JournalEntry::create([
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'journal_number' => $journalNumber,
                'accounting_period_id' => $period->id,
                'posting_date' => $dateStr,
                'journal_type' => $journalType,
                'status' => JournalStatusEnum::POSTED,
                'source_module' => $sourceModule,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'source_number' => $sourceNumber,
                'idempotency_key' => $idempotencyKey,
                'posting_rule_version_id' => $postingRuleVersionId,
                'reversal_of_journal_id' => $reversalOfJournalId,
                'currency_code' => $currencyCode,
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'notes' => $notes,
                'created_by_user_id' => $actor?->id,
                'posted_by_user_id' => $actor?->id,
                'posted_at' => now(),
            ]);

            // Insert Lines & Update Account Period Balances
            foreach ($lines as $index => $line) {
                $lineDebit = bcadd((string) $line->debitAmount, '0', 2);
                $lineCredit = bcadd((string) $line->creditAmount, '0', 2);

                JournalLine::create([
                    'tenant_id' => $tenantId,
                    'journal_entry_id' => $journal->id,
                    'line_number' => $index + 1,
                    'account_id' => $line->accountId,
                    'store_id' => $line->storeId ?? $storeId,
                    'debit_amount' => $lineDebit,
                    'credit_amount' => $lineCredit,
                    'description' => $line->description,
                    'customer_id' => $line->customerId,
                    'supplier_id' => $line->supplierId,
                    'tax_code_id' => $line->taxCodeId,
                ]);

                // Update saldo teragregasi account_period_balances
                $this->applyBalanceMovement(
                    $tenantId,
                    $period->id,
                    $line->accountId,
                    $line->storeId ?? $storeId,
                    $lineDebit,
                    $lineCredit,
                    $accounts[$line->accountId]->normal_balance
                );
            }

            return $journal;
        });
    }

    protected function applyBalanceMovement(
        int $tenantId,
        int $periodId,
        int $accountId,
        ?int $storeId,
        string $debitAmount,
        string $creditAmount,
        NormalBalanceEnum $normalBalance
    ): void {
        $scopeKey = $storeId ?? 0;

        $balance = AccountPeriodBalance::withoutTenantScope()->firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'accounting_period_id' => $periodId,
                'account_id' => $accountId,
                'store_scope_key' => $scopeKey,
            ],
            [
                'store_id' => $storeId,
                'opening_debit' => '0.00',
                'opening_credit' => '0.00',
                'period_debit' => '0.00',
                'period_credit' => '0.00',
                'closing_debit' => '0.00',
                'closing_credit' => '0.00',
                'net_movement' => '0.00',
            ]
        );

        $newPeriodDebit = bcadd((string) $balance->period_debit, $debitAmount, 2);
        $newPeriodCredit = bcadd((string) $balance->period_credit, $creditAmount, 2);

        // Net movement disesuaikan dengan saldo normal
        $movement = ($normalBalance === NormalBalanceEnum::DEBIT)
            ? bcsub($debitAmount, $creditAmount, 2)
            : bcsub($creditAmount, $debitAmount, 2);

        $newNetMovement = bcadd((string) $balance->net_movement, $movement, 2);

        $newClosingDebit = bcadd((string) $balance->opening_debit, $newPeriodDebit, 2);
        $newClosingCredit = bcadd((string) $balance->opening_credit, $newPeriodCredit, 2);

        $balance->period_debit = $newPeriodDebit;
        $balance->period_credit = $newPeriodCredit;
        $balance->closing_debit = $newClosingDebit;
        $balance->closing_credit = $newClosingCredit;
        $balance->net_movement = $newNetMovement;
        $balance->save();
    }

    /**
     * Memposting jurnal manual dari DTO.
     */
    public function postManualJournal(ManualJournalDto $dto, ?User $actor = null): JournalEntry
    {
        $journalType = ($dto->journalType === 'OPENING_BALANCE')
            ? JournalTypeEnum::OPENING_BALANCE
            : JournalTypeEnum::MANUAL_ADJUSTMENT;

        return $this->createAndPostJournal(
            tenantId: $dto->tenantId,
            storeId: $dto->storeId,
            postingDate: $dto->postingDate,
            idempotencyKey: $dto->idempotencyKey,
            lines: $dto->lines,
            sourceModule: 'FINANCE',
            sourceType: 'MANUAL',
            sourceId: null,
            sourceNumber: null,
            journalType: $journalType,
            notes: $dto->notes,
            currencyCode: $dto->currencyCode,
            actor: $actor
        );
    }

    /**
     * Membalikkan (reverse) jurnal yang sudah POSTED secara aman dan atomik.
     * FIN-M7-08: Dilarang mengubah nilai langsung; buat jurnal pembalik berpasangan.
     */
    public function reverseJournal(
        JournalEntry $originalJournal,
        string|CarbonInterface $reversalDate,
        string $reason,
        ?User $actor = null
    ): JournalEntry {
        if ($originalJournal->status !== JournalStatusEnum::POSTED) {
            throw new DomainException("FIN-M7-08: Hanya jurnal berstatus POSTED yang dapat dibalikkan. Status jurnal saat ini: {$originalJournal->status->value}.");
        }

        if ($originalJournal->reversed_by_journal_id !== null) {
            throw new DomainException("FIN-M7-08: Jurnal #{$originalJournal->journal_number} sudah pernah dibalikkan sebelumnya.");
        }

        // Muat baris-baris jurnal asli
        $originalLines = $originalJournal->lines()->orderBy('line_number')->get();
        if ($originalLines->isEmpty()) {
            throw new DomainException('FIN-M7-01: Jurnal asal tidak memiliki baris untuk dibalikkan.');
        }

        // Buat baris kebalikan: Debit -> Credit, Credit -> Debit
        $reversalLines = [];
        foreach ($originalLines as $line) {
            $reversalLines[] = new JournalLineDto(
                accountId: $line->account_id,
                debitAmount: (string) $line->credit_amount,
                creditAmount: (string) $line->debit_amount,
                description: "Reversal: {$line->description}",
                storeId: $line->store_id,
                customerId: $line->customer_id,
                supplierId: $line->supplier_id,
                taxCodeId: $line->tax_code_id
            );
        }

        $reversalIdempotencyKey = "REV-{$originalJournal->id}-".Carbon::parse($reversalDate)->format('YmdHis');

        return DB::transaction(function () use (
            $originalJournal, $reversalDate, $reason, $actor, $reversalLines, $reversalIdempotencyKey
        ) {
            $reversalJournal = $this->createAndPostJournal(
                tenantId: $originalJournal->tenant_id,
                storeId: $originalJournal->store_id,
                postingDate: $reversalDate,
                idempotencyKey: $reversalIdempotencyKey,
                lines: $reversalLines,
                sourceModule: 'FINANCE',
                sourceType: 'REVERSAL',
                sourceId: $originalJournal->id,
                sourceNumber: $originalJournal->journal_number,
                journalType: JournalTypeEnum::REVERSAL,
                reversalOfJournalId: $originalJournal->id,
                notes: "Reversal of {$originalJournal->journal_number}. Alasan: {$reason}",
                currencyCode: $originalJournal->currency_code,
                actor: $actor
            );

            // Update status jurnal asli menjadi REVERSED dan tandai pembaliknya
            $originalJournal->update([
                'status' => JournalStatusEnum::REVERSED,
                'reversed_by_journal_id' => $reversalJournal->id,
                'reversed_at' => now(),
                'reversal_reason' => $reason,
            ]);

            return $reversalJournal;
        });
    }
}
