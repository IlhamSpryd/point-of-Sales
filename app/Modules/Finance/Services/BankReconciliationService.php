<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Models\User;
use App\Modules\Finance\Enums\BankReconciliationMatchTypeEnum;
use App\Modules\Finance\Enums\BankReconciliationSessionStatusEnum;
use App\Modules\Finance\Enums\BankStatementLineMatchStatusEnum;
use App\Modules\Finance\Enums\CashBankTransactionStatusEnum;
use App\Modules\Finance\Enums\CashBankTransactionTypeEnum;
use App\Modules\Finance\Models\BankReconciliationMatch;
use App\Modules\Finance\Models\BankReconciliationSession;
use App\Modules\Finance\Models\BankStatement;
use App\Modules\Finance\Models\BankStatementLine;
use App\Modules\Finance\Models\CashBankAccount;
use App\Modules\Finance\Models\CashBankTransaction;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BankReconciliationService
{
    public function __construct(
        protected CashBankTransactionService $transactionService
    ) {}

    /**
     * Menghitung hash sidik jari baris rekening koran (SHA-256) untuk mencegah impor ganda.
     */
    public function computeFingerprintHash(
        int|string $accountId,
        string|CarbonInterface $valueDate,
        string $debitAmount,
        string $creditAmount,
        ?string $reference,
        ?string $description
    ): string {
        $dateStr = $valueDate instanceof CarbonInterface ? $valueDate->toDateString() : Carbon::parse($valueDate)->toDateString();
        $normDebit = bcadd($debitAmount, '0', 2);
        $normCredit = bcadd($creditAmount, '0', 2);
        $normRef = trim((string) $reference);
        $normDesc = trim((string) $description);

        $payload = "{$accountId}|{$dateStr}|{$normDebit}|{$normCredit}|{$normRef}|{$normDesc}";

        return hash('sha256', $payload);
    }

    /**
     * Mengimpor header rekening koran beserta baris mutasi ke dalam database dengan proteksi duplikasi fingerprint.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function importStatement(
        CashBankAccount $account,
        array $lines,
        CarbonInterface|string $statementDate,
        string $statementNumber,
        ?string $filePath,
        User $actor,
        ?string $openingBalance = null,
        ?string $closingBalance = null
    ): BankStatement {
        $tenantId = $account->tenant_id;
        $dateStr = $statementDate instanceof CarbonInterface ? $statementDate->toDateString() : Carbon::parse($statementDate)->toDateString();

        if (empty($lines)) {
            throw new DomainException('Data baris mutasi rekening koran tidak boleh kosong.');
        }

        return DB::transaction(function () use ($tenantId, $account, $lines, $dateStr, $statementNumber, $filePath, $openingBalance, $closingBalance, $actor) {
            // 1. Cek duplikasi nomor rekening koran per tenant
            $statementExists = BankStatement::withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->where('statement_number', $statementNumber)
                ->exists();

            if ($statementExists) {
                throw new DomainException("Nomor rekening koran '{$statementNumber}' sudah pernah diimpor.");
            }

            // 2. Pra-validasi duplikasi fingerprint pada baris impor
            $batchFingerprints = [];
            $preparedLines = [];

            foreach ($lines as $index => $row) {
                $lineDate = $row['value_date'] ?? $dateStr;
                $lineDateStr = $lineDate instanceof CarbonInterface ? $lineDate->toDateString() : Carbon::parse($lineDate)->toDateString();
                $debit = bcadd((string) ($row['debit_amount'] ?? '0.00'), '0', 2);
                $credit = bcadd((string) ($row['credit_amount'] ?? '0.00'), '0', 2);
                $ref = $row['external_reference'] ?? null;
                $desc = $row['description'] ?? null;
                $balance = bcadd((string) ($row['running_balance'] ?? '0.00'), '0', 2);

                $fingerprint = $this->computeFingerprintHash($account->id, $lineDateStr, $debit, $credit, $ref, $desc);

                if (in_array($fingerprint, $batchFingerprints, true)) {
                    throw new DomainException("FIN-M7-11: Terdapat baris mutasi ganda di dalam dokumen impor yang sama pada baris #{$index}.");
                }

                $batchFingerprints[] = $fingerprint;

                // Cek ke basis data apakah fingerprint ini sudah pernah diimpor untuk akun kas/bank ini
                $alreadyImported = BankStatementLine::withoutTenantScope()
                    ->where('tenant_id', $tenantId)
                    ->where('cash_bank_account_id', $account->id)
                    ->where('line_fingerprint_hash', $fingerprint)
                    ->exists();

                if ($alreadyImported) {
                    throw new DomainException("FIN-M7-11: Baris rekening koran duplikat terdeteksi pada akun #{$account->id} (fingerprint: {$fingerprint}). Impor dibatalkan.");
                }

                $preparedLines[] = [
                    'line_number' => $index + 1,
                    'value_date' => $lineDateStr,
                    'external_reference' => $ref,
                    'debit_amount' => $debit,
                    'credit_amount' => $credit,
                    'running_balance' => $balance,
                    'description' => $desc,
                    'line_fingerprint_hash' => $fingerprint,
                ];
            }

            // Tentukan saldo awal & saldo akhir jika tidak disediakan
            $resolvedOpening = $openingBalance ?? ($preparedLines[0]['running_balance'] ?? (string) $account->opening_balance);
            $lastIndex = count($preparedLines) - 1;
            $resolvedClosing = $closingBalance ?? ($preparedLines[$lastIndex]['running_balance'] ?? $resolvedOpening);

            // 3. Simpan header BankStatement
            $statement = BankStatement::withoutTenantScope()->create([
                'tenant_id' => $tenantId,
                'cash_bank_account_id' => $account->id,
                'statement_number' => $statementNumber,
                'statement_date' => $dateStr,
                'opening_balance' => bcadd($resolvedOpening, '0', 2),
                'closing_balance' => bcadd($resolvedClosing, '0', 2),
                'imported_file_path' => $filePath,
                'imported_by_user_id' => $actor->id,
                'status' => 'OPEN',
            ]);

            // 4. Simpan baris BankStatementLine
            foreach ($preparedLines as $line) {
                BankStatementLine::withoutTenantScope()->create([
                    'tenant_id' => $tenantId,
                    'bank_statement_id' => $statement->id,
                    'cash_bank_account_id' => $account->id,
                    'line_number' => $line['line_number'],
                    'value_date' => $line['value_date'],
                    'external_reference' => $line['external_reference'],
                    'debit_amount' => $line['debit_amount'],
                    'credit_amount' => $line['credit_amount'],
                    'running_balance' => $line['running_balance'],
                    'description' => $line['description'],
                    'line_fingerprint_hash' => $line['line_fingerprint_hash'],
                    'match_status' => BankStatementLineMatchStatusEnum::UNMATCHED,
                ]);
            }

            return $statement->load('lines');
        });
    }

    /**
     * Mengimpor rekening koran langsung dari string CSV atau file path CSV.
     */
    public function importFromCsv(
        CashBankAccount $account,
        string $csvContentOrPath,
        CarbonInterface|string $statementDate,
        string $statementNumber,
        User $actor,
        ?string $openingBalance = null,
        ?string $closingBalance = null
    ): BankStatement {
        $content = is_file($csvContentOrPath)
            ? (string) file_get_contents($csvContentOrPath)
            : $csvContentOrPath;

        $rows = array_map('str_getcsv', explode("\n", trim($content)));
        if (count($rows) < 2) {
            throw new DomainException('Berkas CSV tidak memiliki baris data mutasi yang valid.');
        }

        $header = array_map(fn ($col) => strtolower(trim((string) $col)), array_shift($rows));

        $lines = [];
        foreach ($rows as $row) {
            if (empty(array_filter($row))) {
                continue;
            }
            $mapped = array_combine($header, $row);

            $lines[] = [
                'value_date' => $mapped['date'] ?? $mapped['value_date'] ?? $mapped['tanggal'] ?? now()->toDateString(),
                'external_reference' => $mapped['reference'] ?? $mapped['external_reference'] ?? $mapped['ref'] ?? null,
                'debit_amount' => $mapped['debit'] ?? $mapped['debit_amount'] ?? '0.00',
                'credit_amount' => $mapped['credit'] ?? $mapped['credit_amount'] ?? '0.00',
                'running_balance' => $mapped['balance'] ?? $mapped['running_balance'] ?? '0.00',
                'description' => $mapped['description'] ?? $mapped['keterangan'] ?? null,
            ];
        }

        return $this->importStatement(
            account: $account,
            lines: $lines,
            statementDate: $statementDate,
            statementNumber: $statementNumber,
            filePath: is_file($csvContentOrPath) ? $csvContentOrPath : null,
            actor: $actor,
            openingBalance: $openingBalance,
            closingBalance: $closingBalance
        );
    }

    /**
     * Memulai sesi rekonsiliasi bank baru untuk periode tertentu.
     */
    public function startSession(
        CashBankAccount $account,
        CarbonInterface|string $endDate,
        string $statementEndingBalance,
        User $actor,
        ?string $notes = null
    ): BankReconciliationSession {
        $tenantId = $account->tenant_id;
        $endDateStr = $endDate instanceof CarbonInterface ? $endDate->toDateString() : Carbon::parse($endDate)->toDateString();

        $sessionNumber = 'BRS-'.$account->id.'-'.date('Ymd').'-'.Str::upper(Str::random(4));
        $bookBalance = (string) $account->fresh()->current_balance;

        $session = BankReconciliationSession::withoutTenantScope()->create([
            'tenant_id' => $tenantId,
            'cash_bank_account_id' => $account->id,
            'session_number' => $sessionNumber,
            'statement_end_date' => $endDateStr,
            'statement_ending_balance' => bcadd($statementEndingBalance, '0', 2),
            'book_ending_balance' => $bookBalance,
            'unreconciled_difference' => '0.00',
            'status' => BankReconciliationSessionStatusEnum::IN_PROGRESS,
            'started_by_user_id' => $actor->id,
            'notes' => $notes,
        ]);

        $this->calculateUnreconciledDifference($session);

        return $session->fresh();
    }

    /**
     * Menghitung ulang selisih rekonsiliasi bank (unreconciled_difference).
     *
     * Selisih = Saldo Akhir Rekening Koran - (Saldo Awal + Total Masuk Matched - Total Keluar Matched)
     */
    public function calculateUnreconciledDifference(BankReconciliationSession $session): string
    {
        $account = $session->account;
        if (! $account) {
            $account = CashBankAccount::withoutTenantScope()->find($session->cash_bank_account_id);
        }

        $session->book_ending_balance = (string) $account->fresh()->current_balance;

        // Tentukan saldo awal sesi
        $prevSession = BankReconciliationSession::withoutTenantScope()
            ->where('tenant_id', $session->tenant_id)
            ->where('cash_bank_account_id', $session->cash_bank_account_id)
            ->where('status', BankReconciliationSessionStatusEnum::COMPLETED)
            ->where('id', '!=', $session->id)
            ->orderByDesc('statement_end_date')
            ->first();

        $openingBalance = $prevSession
            ? (string) $prevSession->statement_ending_balance
            : (string) $account->opening_balance;

        // Ambil seluruh pencocokan aktif pada sesi ini
        $matches = BankReconciliationMatch::withoutTenantScope()
            ->where('reconciliation_session_id', $session->id)
            ->with('transaction')
            ->get();

        $inboundMatched = '0.00';
        $outboundMatched = '0.00';

        foreach ($matches as $match) {
            $txn = $match->transaction;
            if (! $txn) {
                continue;
            }

            $isInbound = in_array($txn->transaction_type, [
                CashBankTransactionTypeEnum::RECEIPT,
                CashBankTransactionTypeEnum::TRANSFER_IN,
                CashBankTransactionTypeEnum::POS_SHIFT_DEPOSIT,
                CashBankTransactionTypeEnum::PG_SETTLEMENT,
                CashBankTransactionTypeEnum::BANK_INTEREST,
            ], true);

            if ($isInbound) {
                $inboundMatched = bcadd($inboundMatched, (string) $match->matched_amount, 2);
            } else {
                $outboundMatched = bcadd($outboundMatched, (string) $match->matched_amount, 2);
            }
        }

        // Saldo yang telah dicocokkan (Cleared Balance)
        $clearedBalance = bcsub(bcadd($openingBalance, $inboundMatched, 2), $outboundMatched, 2);

        // Selisih = Saldo Rekening Koran - Saldo Dicocokkan
        $difference = bcsub((string) $session->statement_ending_balance, $clearedBalance, 2);

        $session->unreconciled_difference = $difference;
        $session->save();

        return $difference;
    }

    /**
     * Menjalankan mesin pencocokan otomatis (*auto-matching*) berbasis nominal eksak, jenis mutasi, dan toleransi tanggal.
     *
     * @return array<int, BankReconciliationMatch>
     */
    public function autoMatch(BankReconciliationSession $session, User $actor, int $dateToleranceDays = 3): array
    {
        if ($session->status !== BankReconciliationSessionStatusEnum::IN_PROGRESS) {
            throw new DomainException("Pencocokan otomatis hanya dapat dijalankan pada sesi berstatus IN_PROGRESS. Status saat ini: {$session->status->value}.");
        }

        $tenantId = $session->tenant_id;
        $accountId = $session->cash_bank_account_id;
        $endDate = $session->statement_end_date;

        return DB::transaction(function () use ($session, $tenantId, $accountId, $endDate, $dateToleranceDays, $actor) {
            // Ambil baris rekening koran yang belum dicocokkan
            $unmatchedLines = BankStatementLine::withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->where('cash_bank_account_id', $accountId)
                ->where('match_status', BankStatementLineMatchStatusEnum::UNMATCHED)
                ->where('value_date', '<=', $endDate)
                ->orderBy('value_date')
                ->get();

            // Ambil transaksi kas/bank buku yang belum direkonsiliasi
            $unreconciledTxns = CashBankTransaction::withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->where('cash_bank_account_id', $accountId)
                ->where('status', CashBankTransactionStatusEnum::POSTED)
                ->where('is_reconciled', false)
                ->where('transaction_date', '<=', $endDate)
                ->orderBy('transaction_date')
                ->get();

            $createdMatches = [];
            $matchedTxnIds = [];

            foreach ($unmatchedLines as $line) {
                $isCredit = bccomp((string) $line->credit_amount, '0.00', 2) > 0;
                $targetAmount = $isCredit ? (string) $line->credit_amount : (string) $line->debit_amount;
                $lineDate = Carbon::parse($line->value_date);

                // Filter kandidat transaksi
                $candidate = $unreconciledTxns->first(function ($txn) use ($matchedTxnIds, $isCredit, $targetAmount, $lineDate, $dateToleranceDays, $line) {
                    if (in_array($txn->id, $matchedTxnIds, true)) {
                        return false;
                    }

                    if (bccomp((string) $txn->amount, $targetAmount, 2) !== 0) {
                        return false;
                    }

                    $txnIsInbound = in_array($txn->transaction_type, [
                        CashBankTransactionTypeEnum::RECEIPT,
                        CashBankTransactionTypeEnum::TRANSFER_IN,
                        CashBankTransactionTypeEnum::POS_SHIFT_DEPOSIT,
                        CashBankTransactionTypeEnum::PG_SETTLEMENT,
                        CashBankTransactionTypeEnum::BANK_INTEREST,
                    ], true);

                    if ($isCredit !== $txnIsInbound) {
                        return false;
                    }

                    $txDate = Carbon::parse($txn->transaction_date);
                    if ($lineDate->diffInDays($txDate) > $dateToleranceDays) {
                        return false;
                    }

                    // Jika nomor referensi cocok, prioritaskan kecocokan sempurna
                    if ($line->external_reference && $txn->reference_number) {
                        return trim((string) $line->external_reference) === trim((string) $txn->reference_number);
                    }

                    return true;
                });

                if ($candidate) {
                    $matchedTxnIds[] = $candidate->id;

                    $match = BankReconciliationMatch::withoutTenantScope()->create([
                        'tenant_id' => $tenantId,
                        'reconciliation_session_id' => $session->id,
                        'bank_statement_line_id' => $line->id,
                        'cash_bank_transaction_id' => $candidate->id,
                        'match_type' => BankReconciliationMatchTypeEnum::EXACT_AUTO,
                        'matched_amount' => $targetAmount,
                        'matched_by_user_id' => $actor->id,
                    ]);

                    $line->match_status = BankStatementLineMatchStatusEnum::MATCHED;
                    $line->save();

                    $candidate->is_reconciled = true;
                    $candidate->reconciled_at = now();
                    $candidate->save();

                    $createdMatches[] = $match;
                }
            }

            $this->calculateUnreconciledDifference($session);

            return $createdMatches;
        });
    }

    /**
     * Melakukan pencocokan manual 1-to-1 antara baris rekening koran dan transaksi buku kas/bank.
     */
    public function manualMatch(
        BankReconciliationSession $session,
        BankStatementLine $statementLine,
        CashBankTransaction $transaction,
        User $actor
    ): BankReconciliationMatch {
        if ($session->status !== BankReconciliationSessionStatusEnum::IN_PROGRESS) {
            throw new DomainException("Pencocokan manual hanya dapat dilakukan pada sesi IN_PROGRESS. Status saat ini: {$session->status->value}.");
        }

        if ($statementLine->cash_bank_account_id !== $session->cash_bank_account_id
            || $transaction->cash_bank_account_id !== $session->cash_bank_account_id) {
            throw new DomainException('Baris rekening koran dan transaksi kas/bank wajib berasal dari akun yang sama dengan sesi rekonsiliasi.');
        }

        if ($statementLine->match_status !== BankStatementLineMatchStatusEnum::UNMATCHED) {
            throw new DomainException("Baris rekening koran #{$statementLine->id} sudah memiliki status pencocokan: {$statementLine->match_status->value}.");
        }

        if ($transaction->is_reconciled || $transaction->status !== CashBankTransactionStatusEnum::POSTED) {
            throw new DomainException("Transaksi kas/bank #{$transaction->id} tidak valid untuk direkonsiliasi (sudah direkonsiliasi atau status bukan POSTED).");
        }

        $isCredit = bccomp((string) $statementLine->credit_amount, '0.00', 2) > 0;
        $targetAmount = $isCredit ? (string) $statementLine->credit_amount : (string) $statementLine->debit_amount;

        if (bccomp((string) $transaction->amount, $targetAmount, 2) !== 0) {
            throw new DomainException("Nominal baris mutasi bank ({$targetAmount}) tidak sama dengan nominal transaksi buku ({$transaction->amount}).");
        }

        return DB::transaction(function () use ($session, $statementLine, $transaction, $targetAmount, $actor) {
            $match = BankReconciliationMatch::withoutTenantScope()->create([
                'tenant_id' => $session->tenant_id,
                'reconciliation_session_id' => $session->id,
                'bank_statement_line_id' => $statementLine->id,
                'cash_bank_transaction_id' => $transaction->id,
                'match_type' => BankReconciliationMatchTypeEnum::MANUAL_1_TO_1,
                'matched_amount' => $targetAmount,
                'matched_by_user_id' => $actor->id,
            ]);

            $statementLine->match_status = BankStatementLineMatchStatusEnum::MATCHED;
            $statementLine->save();

            $transaction->is_reconciled = true;
            $transaction->reconciled_at = now();
            $transaction->save();

            $this->calculateUnreconciledDifference($session);

            return $match;
        });
    }

    /**
     * Membatalkan pencocokan transaksi (*unmatch*).
     */
    public function unmatch(BankReconciliationMatch $match, User $actor): void
    {
        $session = $match->session;
        if ($session->status !== BankReconciliationSessionStatusEnum::IN_PROGRESS) {
            throw new DomainException("Pencocokan hanya dapat dibatalkan pada sesi IN_PROGRESS. Status saat ini: {$session->status->value}.");
        }

        DB::transaction(function () use ($match, $session) {
            $line = $match->statementLine;
            $txn = $match->transaction;

            if ($line) {
                $line->match_status = BankStatementLineMatchStatusEnum::UNMATCHED;
                $line->save();
            }

            if ($txn) {
                $txn->is_reconciled = false;
                $txn->reconciled_at = null;
                $txn->save();
            }

            $match->delete();

            $this->calculateUnreconciledDifference($session);
        });
    }

    /**
     * Membuat entri penyesuaian (Biaya Admin Bank atau Pendapatan Bunga) langsung dari baris rekening koran.
     */
    public function createAdjustmentEntry(
        BankReconciliationSession $session,
        BankStatementLine $statementLine,
        string $adjustmentType,
        string $description,
        User $actor
    ): BankReconciliationMatch {
        if ($session->status !== BankReconciliationSessionStatusEnum::IN_PROGRESS) {
            throw new DomainException('Penyesuaian transaksi hanya dapat dibuat pada sesi rekonsiliasi yang sedang aktif (IN_PROGRESS).');
        }

        if ($statementLine->match_status !== BankStatementLineMatchStatusEnum::UNMATCHED) {
            throw new DomainException("Baris rekening koran #{$statementLine->id} sudah memiliki status {$statementLine->match_status->value}.");
        }

        $typeEnum = CashBankTransactionTypeEnum::from($adjustmentType);
        $amount = bccomp((string) $statementLine->debit_amount, '0.00', 2) > 0
            ? (string) $statementLine->debit_amount
            : (string) $statementLine->credit_amount;

        return DB::transaction(function () use ($session, $statementLine, $typeEnum, $amount, $description, $actor) {
            $account = $session->account;

            // Catat mutasi penyesuaian kas/bank dan posting jurnal GL
            $adjTxn = $this->transactionService->recordBankAdjustment(
                account: $account,
                type: $typeEnum,
                amount: $amount,
                date: $statementLine->value_date,
                description: $description,
                referenceNumber: $statementLine->external_reference,
                actor: $actor,
                idempotencyKey: "reconcile:adj:{$session->id}:line:{$statementLine->id}"
            );

            // Tandai langsung transaksi sebagai terevaluasi/reconciled
            $adjTxn->is_reconciled = true;
            $adjTxn->reconciled_at = now();
            $adjTxn->save();

            $statementLine->match_status = BankStatementLineMatchStatusEnum::ADJUSTED;
            $statementLine->save();

            // Hubungkan match
            $match = BankReconciliationMatch::withoutTenantScope()->create([
                'tenant_id' => $session->tenant_id,
                'reconciliation_session_id' => $session->id,
                'bank_statement_line_id' => $statementLine->id,
                'cash_bank_transaction_id' => $adjTxn->id,
                'match_type' => BankReconciliationMatchTypeEnum::ADJUSTMENT_ENTRY,
                'matched_amount' => $amount,
                'matched_by_user_id' => $actor->id,
            ]);

            $this->calculateUnreconciledDifference($session);

            return $match;
        });
    }

    /**
     * Menyelesaikan sesi rekonsiliasi bank dengan gerbang penolakan ketat jika selisih != 0.00 (FIN-M7-11).
     */
    public function completeSession(BankReconciliationSession $session, User $actor): BankReconciliationSession
    {
        $diff = $this->calculateUnreconciledDifference($session);

        if (bccomp($diff, '0.00', 2) !== 0) {
            throw new DomainException("FIN-M7-11: Sesi rekonsiliasi tidak dapat diselesaikan karena masih terdapat selisih sebesar {$diff}. Selisih wajib 0.00.");
        }

        $session->status = BankReconciliationSessionStatusEnum::COMPLETED;
        $session->completed_by_user_id = $actor->id;
        $session->completed_at = now();
        $session->save();

        return $session;
    }

    /**
     * Membuka kembali (*reopen*) sesi rekonsiliasi yang telah selesai untuk koreksi audit.
     */
    public function reopenSession(BankReconciliationSession $session, User $actor, string $reason): BankReconciliationSession
    {
        if ($session->status !== BankReconciliationSessionStatusEnum::COMPLETED) {
            throw new DomainException("Hanya sesi rekonsiliasi berstatus COMPLETED yang dapat dibuka kembali. Status saat ini: {$session->status->value}.");
        }

        $session->status = BankReconciliationSessionStatusEnum::REOPENED;
        $session->notes = trim(($session->notes ?? '')."\n[REOPENED at ".now()->toDateTimeString()." by user #{$actor->id}]: {$reason}");
        $session->save();

        return $session;
    }
}
