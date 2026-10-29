<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Models\User;
use App\Modules\Finance\DataTransferObjects\JournalLineDto;
use App\Modules\Finance\Enums\CashBankAccountTypeEnum;
use App\Modules\Finance\Enums\CashBankTransactionStatusEnum;
use App\Modules\Finance\Enums\CashBankTransactionTypeEnum;
use App\Modules\Finance\Models\CashBankAccount;
use App\Modules\Finance\Models\CashBankTransaction;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Purchasing\Services\DocumentSequenceService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CashBankTransactionService
{
    public function __construct(
        protected JournalEntryService $journalEntryService,
        protected ChartOfAccountService $coaService,
        protected DocumentSequenceService $sequenceService
    ) {}

    /**
     * Mencatat penerimaan kas / bank non-invoice (misal: setoran modal, pendapatan lain-lain).
     *
     * @param  array<string, mixed>  $data
     */
    public function recordReceipt(CashBankAccount $account, array $data, User $actor): CashBankTransaction
    {
        $tenantId = $account->tenant_id;
        $amount = bcadd((string) ($data['amount'] ?? '0.00'), '0', 2);

        if (bccomp($amount, '0.00', 2) <= 0) {
            throw new DomainException('FIN-M7-03: Nominal penerimaan kas/bank wajib lebih besar dari 0.');
        }

        $date = $data['transaction_date'] ?? now()->toDateString();
        $dateStr = $date instanceof CarbonInterface ? $date->toDateString() : Carbon::parse($date)->toDateString();
        $description = (string) ($data['description'] ?? "Penerimaan Kas/Bank #{$account->account_name}");
        $referenceNumber = $data['reference_number'] ?? null;
        $idempotencyKey = $data['idempotency_key'] ?? "cbt:rec:{$tenantId}:".Str::uuid();

        return DB::transaction(function () use ($tenantId, $account, $amount, $dateStr, $description, $referenceNumber, $idempotencyKey, $data, $actor) {
            $lockedAccount = CashBankAccount::withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($account->id);

            $newBalance = bcadd((string) $lockedAccount->current_balance, $amount, 2);
            $lockedAccount->current_balance = $newBalance;
            $lockedAccount->save();

            $txnNumber = $data['transaction_number'] ?? ('CBT-REC-'.$lockedAccount->id.'-'.date('Ymd').'-'.Str::upper(Str::random(4)));

            $journalId = null;
            if (! empty($data['offset_gl_account_id'])) {
                $lines = [
                    new JournalLineDto(
                        accountId: (int) $lockedAccount->gl_account_id,
                        debitAmount: $amount,
                        creditAmount: '0.00',
                        description: $description,
                        storeId: $lockedAccount->store_id
                    ),
                    new JournalLineDto(
                        accountId: (int) $data['offset_gl_account_id'],
                        debitAmount: '0.00',
                        creditAmount: $amount,
                        description: $description,
                        storeId: $lockedAccount->store_id
                    ),
                ];

                $journal = $this->journalEntryService->createAndPostJournal(
                    tenantId: $tenantId,
                    storeId: $lockedAccount->store_id,
                    postingDate: $dateStr,
                    idempotencyKey: "journal:cbt_rec:{$tenantId}:{$txnNumber}",
                    lines: $lines,
                    sourceModule: 'Finance',
                    sourceType: 'cash_bank_receipt',
                    sourceNumber: $txnNumber,
                    notes: $description,
                    actor: $actor
                );

                $journalId = $journal->id;
            }

            return CashBankTransaction::withoutTenantScope()->create([
                'tenant_id' => $tenantId,
                'store_id' => $lockedAccount->store_id,
                'cash_bank_account_id' => $lockedAccount->id,
                'journal_entry_id' => $journalId,
                'transaction_number' => $txnNumber,
                'transaction_type' => CashBankTransactionTypeEnum::RECEIPT,
                'transaction_date' => $dateStr,
                'amount' => $amount,
                'running_balance_after' => $newBalance,
                'description' => $description,
                'reference_number' => $referenceNumber,
                'idempotency_key' => $idempotencyKey,
                'status' => CashBankTransactionStatusEnum::POSTED,
                'created_by_user_id' => $actor->id,
                'posted_by_user_id' => $actor->id,
            ]);
        });
    }

    /**
     * Mencatat pengeluaran kas / bank non-invoice (misal: pengeluaran operasional langsung).
     *
     * @param  array<string, mixed>  $data
     */
    public function recordDisbursement(CashBankAccount $account, array $data, User $actor): CashBankTransaction
    {
        $tenantId = $account->tenant_id;
        $amount = bcadd((string) ($data['amount'] ?? '0.00'), '0', 2);

        if (bccomp($amount, '0.00', 2) <= 0) {
            throw new DomainException('FIN-M7-03: Nominal pengeluaran kas/bank wajib lebih besar dari 0.');
        }

        $date = $data['transaction_date'] ?? now()->toDateString();
        $dateStr = $date instanceof CarbonInterface ? $date->toDateString() : Carbon::parse($date)->toDateString();
        $description = (string) ($data['description'] ?? "Pengeluaran Kas/Bank #{$account->account_name}");
        $referenceNumber = $data['reference_number'] ?? null;
        $idempotencyKey = $data['idempotency_key'] ?? "cbt:disb:{$tenantId}:".Str::uuid();

        return DB::transaction(function () use ($tenantId, $account, $amount, $dateStr, $description, $referenceNumber, $idempotencyKey, $data, $actor) {
            $lockedAccount = CashBankAccount::withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($account->id);

            if (bccomp((string) $lockedAccount->current_balance, $amount, 2) < 0) {
                throw new DomainException("Saldo kas/bank '{$lockedAccount->account_name}' ({$lockedAccount->current_balance}) tidak mencukupi untuk pengeluaran sebesar {$amount}.");
            }

            $newBalance = bcsub((string) $lockedAccount->current_balance, $amount, 2);
            $lockedAccount->current_balance = $newBalance;
            $lockedAccount->save();

            $txnNumber = $data['transaction_number'] ?? ('CBT-DISB-'.$lockedAccount->id.'-'.date('Ymd').'-'.Str::upper(Str::random(4)));

            $journalId = null;
            if (! empty($data['offset_gl_account_id'])) {
                $lines = [
                    new JournalLineDto(
                        accountId: (int) $data['offset_gl_account_id'],
                        debitAmount: $amount,
                        creditAmount: '0.00',
                        description: $description,
                        storeId: $lockedAccount->store_id
                    ),
                    new JournalLineDto(
                        accountId: (int) $lockedAccount->gl_account_id,
                        debitAmount: '0.00',
                        creditAmount: $amount,
                        description: $description,
                        storeId: $lockedAccount->store_id
                    ),
                ];

                $journal = $this->journalEntryService->createAndPostJournal(
                    tenantId: $tenantId,
                    storeId: $lockedAccount->store_id,
                    postingDate: $dateStr,
                    idempotencyKey: "journal:cbt_disb:{$tenantId}:{$txnNumber}",
                    lines: $lines,
                    sourceModule: 'Finance',
                    sourceType: 'cash_bank_disbursement',
                    sourceNumber: $txnNumber,
                    notes: $description,
                    actor: $actor
                );

                $journalId = $journal->id;
            }

            return CashBankTransaction::withoutTenantScope()->create([
                'tenant_id' => $tenantId,
                'store_id' => $lockedAccount->store_id,
                'cash_bank_account_id' => $lockedAccount->id,
                'journal_entry_id' => $journalId,
                'transaction_number' => $txnNumber,
                'transaction_type' => CashBankTransactionTypeEnum::DISBURSEMENT,
                'transaction_date' => $dateStr,
                'amount' => $amount,
                'running_balance_after' => $newBalance,
                'description' => $description,
                'reference_number' => $referenceNumber,
                'idempotency_key' => $idempotencyKey,
                'status' => CashBankTransactionStatusEnum::POSTED,
                'created_by_user_id' => $actor->id,
                'posted_by_user_id' => $actor->id,
            ]);
        });
    }

    /**
     * Melakukan transfer internal antar akun Kas/Bank secara atomik (Debit Dest, Credit Source).
     *
     * @return array{out_transaction: CashBankTransaction, in_transaction: CashBankTransaction, journal_entry: JournalEntry}
     */
    public function transferInternal(
        CashBankAccount $sourceAccount,
        CashBankAccount $destinationAccount,
        string $amount,
        CarbonInterface|string $date,
        string $description,
        ?string $referenceNumber,
        User $actor,
        ?string $idempotencyKey = null
    ): array {
        if ($sourceAccount->id === $destinationAccount->id) {
            throw new DomainException('Akun asal dan akun tujuan transfer internal tidak boleh sama.');
        }

        if ($sourceAccount->tenant_id !== $destinationAccount->tenant_id) {
            throw new DomainException('Transfer internal antar akun beda tenant tidak diizinkan.');
        }

        $tenantId = $sourceAccount->tenant_id;
        $normAmount = bcadd($amount, '0', 2);

        if (bccomp($normAmount, '0.00', 2) <= 0) {
            throw new DomainException('FIN-M7-03: Nominal transfer kas/bank wajib lebih besar dari 0.');
        }

        $dateStr = $date instanceof CarbonInterface ? $date->toDateString() : Carbon::parse($date)->toDateString();
        $idempotencyBase = $idempotencyKey ?: Str::uuid()->toString();

        return DB::transaction(function () use ($tenantId, $sourceAccount, $destinationAccount, $normAmount, $dateStr, $description, $referenceNumber, $idempotencyBase, $actor) {
            // Lock akun dalam urutan ID ascending untuk mencegah deadlock
            $firstId = min($sourceAccount->id, $destinationAccount->id);
            $secondId = max($sourceAccount->id, $destinationAccount->id);

            CashBankAccount::withoutTenantScope()->where('tenant_id', $tenantId)->where('id', $firstId)->lockForUpdate()->first();
            CashBankAccount::withoutTenantScope()->where('tenant_id', $tenantId)->where('id', $secondId)->lockForUpdate()->first();

            $lockedSource = CashBankAccount::withoutTenantScope()->where('tenant_id', $tenantId)->findOrFail($sourceAccount->id);
            $lockedDest = CashBankAccount::withoutTenantScope()->where('tenant_id', $tenantId)->findOrFail($destinationAccount->id);

            // Cek saldo akun pengirim
            if (bccomp((string) $lockedSource->current_balance, $normAmount, 2) < 0) {
                throw new DomainException("Saldo kas/bank '{$lockedSource->account_name}' ({$lockedSource->current_balance}) tidak mencukupi untuk transfer sebesar {$normAmount}.");
            }

            // Update Saldo Sumber & Tujuan
            $sourceNewBalance = bcsub((string) $lockedSource->current_balance, $normAmount, 2);
            $lockedSource->current_balance = $sourceNewBalance;
            $lockedSource->save();

            $destNewBalance = bcadd((string) $lockedDest->current_balance, $normAmount, 2);
            $lockedDest->current_balance = $destNewBalance;
            $lockedDest->save();

            $transferRef = $referenceNumber ?: ('TRF-'.date('Ymd').'-'.Str::upper(Str::random(6)));
            $outTxnNumber = 'CBT-OUT-'.$lockedSource->id.'-'.date('Ymd').'-'.Str::upper(Str::random(4));
            $inTxnNumber = 'CBT-IN-'.$lockedDest->id.'-'.date('Ymd').'-'.Str::upper(Str::random(4));

            // Posting Jurnal GL: Dr Akun Tujuan, Cr Akun Asal
            $lines = [
                new JournalLineDto(
                    accountId: (int) $lockedDest->gl_account_id,
                    debitAmount: $normAmount,
                    creditAmount: '0.00',
                    description: "Penerimaan transfer dari {$lockedSource->account_name}: {$description}",
                    storeId: $lockedDest->store_id
                ),
                new JournalLineDto(
                    accountId: (int) $lockedSource->gl_account_id,
                    debitAmount: '0.00',
                    creditAmount: $normAmount,
                    description: "Pengeluaran transfer ke {$lockedDest->account_name}: {$description}",
                    storeId: $lockedSource->store_id
                ),
            ];

            $journal = $this->journalEntryService->createAndPostJournal(
                tenantId: $tenantId,
                storeId: $lockedSource->store_id ?? $lockedDest->store_id,
                postingDate: $dateStr,
                idempotencyKey: "journal:transfer:{$tenantId}:{$idempotencyBase}",
                lines: $lines,
                sourceModule: 'Finance',
                sourceType: 'cash_bank_transfer',
                sourceNumber: $transferRef,
                notes: $description,
                actor: $actor
            );

            // Transaksi Keluar pada Akun Sumber
            $outTxn = CashBankTransaction::withoutTenantScope()->create([
                'tenant_id' => $tenantId,
                'store_id' => $lockedSource->store_id,
                'cash_bank_account_id' => $lockedSource->id,
                'transfer_pair_account_id' => $lockedDest->id,
                'journal_entry_id' => $journal->id,
                'transaction_number' => $outTxnNumber,
                'transaction_type' => CashBankTransactionTypeEnum::TRANSFER_OUT,
                'transaction_date' => $dateStr,
                'amount' => $normAmount,
                'running_balance_after' => $sourceNewBalance,
                'description' => $description,
                'reference_number' => $transferRef,
                'idempotency_key' => "cbt:transfer:out:{$tenantId}:{$idempotencyBase}",
                'status' => CashBankTransactionStatusEnum::POSTED,
                'created_by_user_id' => $actor->id,
                'posted_by_user_id' => $actor->id,
            ]);

            // Transaksi Masuk pada Akun Tujuan
            $inTxn = CashBankTransaction::withoutTenantScope()->create([
                'tenant_id' => $tenantId,
                'store_id' => $lockedDest->store_id,
                'cash_bank_account_id' => $lockedDest->id,
                'transfer_pair_account_id' => $lockedSource->id,
                'journal_entry_id' => $journal->id,
                'transaction_number' => $inTxnNumber,
                'transaction_type' => CashBankTransactionTypeEnum::TRANSFER_IN,
                'transaction_date' => $dateStr,
                'amount' => $normAmount,
                'running_balance_after' => $destNewBalance,
                'description' => $description,
                'reference_number' => $transferRef,
                'idempotency_key' => "cbt:transfer:in:{$tenantId}:{$idempotencyBase}",
                'status' => CashBankTransactionStatusEnum::POSTED,
                'created_by_user_id' => $actor->id,
                'posted_by_user_id' => $actor->id,
            ]);

            return [
                'out_transaction' => $outTxn,
                'in_transaction' => $inTxn,
                'journal_entry' => $journal,
            ];
        });
    }

    /**
     * Mencatat setoran penutupan shift kasir POS dari laci kasir ke brankas toko atau rekening bank.
     *
     * @return array{drawer_transaction: CashBankTransaction, deposit_transaction: CashBankTransaction, journal_entry: JournalEntry}
     */
    public function recordPosShiftDeposit(
        CashBankAccount $drawerAccount,
        CashBankAccount $safeOrBankAccount,
        string $amount,
        CarbonInterface|string $date,
        ?string $referenceNumber,
        User $actor,
        ?string $idempotencyKey = null,
        ?int $shiftId = null
    ): array {
        if ($drawerAccount->account_type !== CashBankAccountTypeEnum::POS_DRAWER_CLEARING) {
            throw new DomainException("Akun sumber setoran kasir wajib bertipe 'POS_DRAWER_CLEARING'.");
        }

        if (! in_array($safeOrBankAccount->account_type, [CashBankAccountTypeEnum::STORE_SAFE, CashBankAccountTypeEnum::BANK_ACCOUNT], true)) {
            throw new DomainException("Akun tujuan setoran kasir wajib bertipe 'STORE_SAFE' atau 'BANK_ACCOUNT'.");
        }

        $tenantId = $drawerAccount->tenant_id;
        $normAmount = bcadd($amount, '0', 2);

        if (bccomp($normAmount, '0.00', 2) <= 0) {
            throw new DomainException('FIN-M7-03: Nominal setoran kasir wajib lebih besar dari 0.');
        }

        $dateStr = $date instanceof CarbonInterface ? $date->toDateString() : Carbon::parse($date)->toDateString();
        $idempotencyBase = $idempotencyKey ?: Str::uuid()->toString();
        $depositRef = $referenceNumber ?: ($shiftId ? "SHIFT-DEP-{$shiftId}" : ('DEP-'.date('Ymd').'-'.Str::upper(Str::random(4))));

        return DB::transaction(function () use ($tenantId, $drawerAccount, $safeOrBankAccount, $normAmount, $dateStr, $depositRef, $idempotencyBase, $shiftId, $actor) {
            $firstId = min($drawerAccount->id, $safeOrBankAccount->id);
            $secondId = max($drawerAccount->id, $safeOrBankAccount->id);

            CashBankAccount::withoutTenantScope()->where('tenant_id', $tenantId)->where('id', $firstId)->lockForUpdate()->first();
            CashBankAccount::withoutTenantScope()->where('tenant_id', $tenantId)->where('id', $secondId)->lockForUpdate()->first();

            $lockedDrawer = CashBankAccount::withoutTenantScope()->where('tenant_id', $tenantId)->findOrFail($drawerAccount->id);
            $lockedSafe = CashBankAccount::withoutTenantScope()->where('tenant_id', $tenantId)->findOrFail($safeOrBankAccount->id);

            $drawerNewBalance = bcsub((string) $lockedDrawer->current_balance, $normAmount, 2);
            $lockedDrawer->current_balance = $drawerNewBalance;
            $lockedDrawer->save();

            $safeNewBalance = bcadd((string) $lockedSafe->current_balance, $normAmount, 2);
            $lockedSafe->current_balance = $safeNewBalance;
            $lockedSafe->save();

            $desc = "Setoran Kas Shift Kasir POS #{$depositRef} ke {$lockedSafe->account_name}";

            // Posting Jurnal GL: Dr STORE_SAFE / BANK_ACCOUNT, Cr POS_DRAWER_CLEARING
            $lines = [
                new JournalLineDto(
                    accountId: (int) $lockedSafe->gl_account_id,
                    debitAmount: $normAmount,
                    creditAmount: '0.00',
                    description: $desc,
                    storeId: $lockedSafe->store_id ?? $lockedDrawer->store_id
                ),
                new JournalLineDto(
                    accountId: (int) $lockedDrawer->gl_account_id,
                    debitAmount: '0.00',
                    creditAmount: $normAmount,
                    description: $desc,
                    storeId: $lockedDrawer->store_id
                ),
            ];

            $journal = $this->journalEntryService->createAndPostJournal(
                tenantId: $tenantId,
                storeId: $lockedDrawer->store_id,
                postingDate: $dateStr,
                idempotencyKey: "journal:pos_shift_dep:{$tenantId}:{$idempotencyBase}",
                lines: $lines,
                sourceModule: 'Finance',
                sourceType: 'pos_shift_deposit',
                sourceId: $shiftId,
                sourceNumber: $depositRef,
                notes: $desc,
                actor: $actor
            );

            $drawerTxnNumber = 'CBT-SDEP-OUT-'.$lockedDrawer->id.'-'.date('Ymd').'-'.Str::upper(Str::random(4));
            $safeTxnNumber = 'CBT-SDEP-IN-'.$lockedSafe->id.'-'.date('Ymd').'-'.Str::upper(Str::random(4));

            $drawerTxn = CashBankTransaction::withoutTenantScope()->create([
                'tenant_id' => $tenantId,
                'store_id' => $lockedDrawer->store_id,
                'cash_bank_account_id' => $lockedDrawer->id,
                'transfer_pair_account_id' => $lockedSafe->id,
                'journal_entry_id' => $journal->id,
                'transaction_number' => $drawerTxnNumber,
                'transaction_type' => CashBankTransactionTypeEnum::POS_SHIFT_DEPOSIT,
                'transaction_date' => $dateStr,
                'amount' => $normAmount,
                'running_balance_after' => $drawerNewBalance,
                'description' => $desc,
                'reference_number' => $depositRef,
                'idempotency_key' => "cbt:pos_shift_dep:drawer:{$tenantId}:{$idempotencyBase}",
                'status' => CashBankTransactionStatusEnum::POSTED,
                'created_by_user_id' => $actor->id,
                'posted_by_user_id' => $actor->id,
            ]);

            $depositTxn = CashBankTransaction::withoutTenantScope()->create([
                'tenant_id' => $tenantId,
                'store_id' => $lockedSafe->store_id,
                'cash_bank_account_id' => $lockedSafe->id,
                'transfer_pair_account_id' => $lockedDrawer->id,
                'journal_entry_id' => $journal->id,
                'transaction_number' => $safeTxnNumber,
                'transaction_type' => CashBankTransactionTypeEnum::POS_SHIFT_DEPOSIT,
                'transaction_date' => $dateStr,
                'amount' => $normAmount,
                'running_balance_after' => $safeNewBalance,
                'description' => $desc,
                'reference_number' => $depositRef,
                'idempotency_key' => "cbt:pos_shift_dep:safe:{$tenantId}:{$idempotencyBase}",
                'status' => CashBankTransactionStatusEnum::POSTED,
                'created_by_user_id' => $actor->id,
                'posted_by_user_id' => $actor->id,
            ]);

            return [
                'drawer_transaction' => $drawerTxn,
                'deposit_transaction' => $depositTxn,
                'journal_entry' => $journal,
            ];
        });
    }

    /**
     * Mencatat pencairan kliring payment gateway / EDC / QRIS ke rekening bank utama dipotong MDR fee (DEC-10).
     *
     * @return array{bank_transaction: CashBankTransaction, pg_transaction: CashBankTransaction, journal_entry: JournalEntry}
     */
    public function recordPaymentGatewaySettlement(
        CashBankAccount $pgClearingAccount,
        CashBankAccount $bankAccount,
        string $grossAmount,
        string $mdrFeeAmount,
        string $netAmount,
        CarbonInterface|string $date,
        ?string $referenceNumber,
        User $actor,
        ?string $idempotencyKey = null
    ): array {
        if ($pgClearingAccount->account_type !== CashBankAccountTypeEnum::PAYMENT_GATEWAY_CLEARING) {
            throw new DomainException("Akun sumber settlement wajib bertipe 'PAYMENT_GATEWAY_CLEARING'.");
        }

        if ($bankAccount->account_type !== CashBankAccountTypeEnum::BANK_ACCOUNT) {
            throw new DomainException("Akun tujuan settlement wajib bertipe 'BANK_ACCOUNT'.");
        }

        $tenantId = $pgClearingAccount->tenant_id;
        $normGross = bcadd($grossAmount, '0', 2);
        $normMdr = bcadd($mdrFeeAmount, '0', 2);
        $normNet = bcadd($netAmount, '0', 2);

        // Invarian FIN-M7-01: Gross === Net + MDR Fee
        $calculatedGross = bcadd($normNet, $normMdr, 2);
        if (bccomp($normGross, $calculatedGross, 2) !== 0) {
            throw new DomainException("FIN-M7-01: Gross amount ({$normGross}) tidak sama dengan Net ({$normNet}) + MDR Fee ({$normMdr}).");
        }

        if (bccomp($normGross, '0.00', 2) <= 0) {
            throw new DomainException('FIN-M7-03: Nominal gross settlement wajib lebih besar dari 0.');
        }

        $dateStr = $date instanceof CarbonInterface ? $date->toDateString() : Carbon::parse($date)->toDateString();
        $idempotencyBase = $idempotencyKey ?: Str::uuid()->toString();
        $settlementRef = $referenceNumber ?: ('PG-SETTLE-'.date('Ymd').'-'.Str::upper(Str::random(4)));

        // Resolve Akun Beban MDR Gateway (PAYMENT_GATEWAY_FEE)
        $mdrAccount = $this->coaService->getAccountBySystemKey('PAYMENT_GATEWAY_FEE', $tenantId);

        return DB::transaction(function () use ($tenantId, $pgClearingAccount, $bankAccount, $normGross, $normMdr, $normNet, $mdrAccount, $dateStr, $settlementRef, $idempotencyBase, $actor) {
            $firstId = min($pgClearingAccount->id, $bankAccount->id);
            $secondId = max($pgClearingAccount->id, $bankAccount->id);

            CashBankAccount::withoutTenantScope()->where('tenant_id', $tenantId)->where('id', $firstId)->lockForUpdate()->first();
            CashBankAccount::withoutTenantScope()->where('tenant_id', $tenantId)->where('id', $secondId)->lockForUpdate()->first();

            $lockedPg = CashBankAccount::withoutTenantScope()->where('tenant_id', $tenantId)->findOrFail($pgClearingAccount->id);
            $lockedBank = CashBankAccount::withoutTenantScope()->where('tenant_id', $tenantId)->findOrFail($bankAccount->id);

            $pgNewBalance = bcsub((string) $lockedPg->current_balance, $normGross, 2);
            $lockedPg->current_balance = $pgNewBalance;
            $lockedPg->save();

            $bankNewBalance = bcadd((string) $lockedBank->current_balance, $normNet, 2);
            $lockedBank->current_balance = $bankNewBalance;
            $lockedBank->save();

            $desc = "Pencairan Settlement Payment Gateway #{$settlementRef} ke {$lockedBank->account_name}";

            // Posting Jurnal GL:
            // Dr Bank Account (Net)
            // Dr Beban MDR (MdrFee) (jika > 0)
            // Cr PG Clearing (Gross)
            $lines = [
                new JournalLineDto(
                    accountId: (int) $lockedBank->gl_account_id,
                    debitAmount: $normNet,
                    creditAmount: '0.00',
                    description: "Penerimaan bersih settlement PG #{$settlementRef}",
                    storeId: $lockedBank->store_id
                ),
            ];

            if (bccomp($normMdr, '0.00', 2) > 0) {
                $lines[] = new JournalLineDto(
                    accountId: (int) $mdrAccount->id,
                    debitAmount: $normMdr,
                    creditAmount: '0.00',
                    description: "Beban komisi MDR settlement PG #{$settlementRef}",
                    storeId: $lockedBank->store_id
                );
            }

            $lines[] = new JournalLineDto(
                accountId: (int) $lockedPg->gl_account_id,
                debitAmount: '0.00',
                creditAmount: $normGross,
                description: "Kliring pencairan settlement PG #{$settlementRef}",
                storeId: $lockedPg->store_id
            );

            $journal = $this->journalEntryService->createAndPostJournal(
                tenantId: $tenantId,
                storeId: $lockedBank->store_id ?? $lockedPg->store_id,
                postingDate: $dateStr,
                idempotencyKey: "journal:pg_settle:{$tenantId}:{$idempotencyBase}",
                lines: $lines,
                sourceModule: 'Finance',
                sourceType: 'pg_settlement',
                sourceNumber: $settlementRef,
                notes: $desc,
                actor: $actor
            );

            $pgTxnNumber = 'CBT-SETTLE-OUT-'.$lockedPg->id.'-'.date('Ymd').'-'.Str::upper(Str::random(4));
            $bankTxnNumber = 'CBT-SETTLE-IN-'.$lockedBank->id.'-'.date('Ymd').'-'.Str::upper(Str::random(4));

            $pgTxn = CashBankTransaction::withoutTenantScope()->create([
                'tenant_id' => $tenantId,
                'store_id' => $lockedPg->store_id,
                'cash_bank_account_id' => $lockedPg->id,
                'transfer_pair_account_id' => $lockedBank->id,
                'journal_entry_id' => $journal->id,
                'transaction_number' => $pgTxnNumber,
                'transaction_type' => CashBankTransactionTypeEnum::TRANSFER_OUT,
                'transaction_date' => $dateStr,
                'amount' => $normGross,
                'running_balance_after' => $pgNewBalance,
                'description' => $desc,
                'reference_number' => $settlementRef,
                'idempotency_key' => "cbt:pg_settle:pg:{$tenantId}:{$idempotencyBase}",
                'status' => CashBankTransactionStatusEnum::POSTED,
                'created_by_user_id' => $actor->id,
                'posted_by_user_id' => $actor->id,
            ]);

            $bankTxn = CashBankTransaction::withoutTenantScope()->create([
                'tenant_id' => $tenantId,
                'store_id' => $lockedBank->store_id,
                'cash_bank_account_id' => $lockedBank->id,
                'transfer_pair_account_id' => $lockedPg->id,
                'journal_entry_id' => $journal->id,
                'transaction_number' => $bankTxnNumber,
                'transaction_type' => CashBankTransactionTypeEnum::PG_SETTLEMENT,
                'transaction_date' => $dateStr,
                'amount' => $normNet,
                'running_balance_after' => $bankNewBalance,
                'description' => $desc,
                'reference_number' => $settlementRef,
                'idempotency_key' => "cbt:pg_settle:bank:{$tenantId}:{$idempotencyBase}",
                'status' => CashBankTransactionStatusEnum::POSTED,
                'created_by_user_id' => $actor->id,
                'posted_by_user_id' => $actor->id,
            ]);

            return [
                'bank_transaction' => $bankTxn,
                'pg_transaction' => $pgTxn,
                'journal_entry' => $journal,
            ];
        });
    }

    /**
     * Mencatat penyesuaian rekening koran bank (Biaya Admin Bank atau Pendapatan Bunga).
     */
    public function recordBankAdjustment(
        CashBankAccount $account,
        CashBankTransactionTypeEnum|string $type,
        string $amount,
        CarbonInterface|string $date,
        string $description,
        ?string $referenceNumber,
        User $actor,
        ?string $idempotencyKey = null
    ): CashBankTransaction {
        $typeEnum = $type instanceof CashBankTransactionTypeEnum ? $type : CashBankTransactionTypeEnum::from((string) $type);

        if (! in_array($typeEnum, [CashBankTransactionTypeEnum::BANK_FEE, CashBankTransactionTypeEnum::BANK_INTEREST], true)) {
            throw new DomainException("Tipe penyesuaian bank hanya diizinkan 'BANK_FEE' atau 'BANK_INTEREST'.");
        }

        $tenantId = $account->tenant_id;
        $normAmount = bcadd($amount, '0', 2);

        if (bccomp($normAmount, '0.00', 2) <= 0) {
            throw new DomainException('FIN-M7-03: Nominal penyesuaian bank wajib lebih besar dari 0.');
        }

        $dateStr = $date instanceof CarbonInterface ? $date->toDateString() : Carbon::parse($date)->toDateString();
        $idempotencyBase = $idempotencyKey ?: Str::uuid()->toString();

        return DB::transaction(function () use ($tenantId, $account, $typeEnum, $normAmount, $dateStr, $description, $referenceNumber, $idempotencyBase, $actor) {
            $lockedAccount = CashBankAccount::withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($account->id);

            $isFee = $typeEnum === CashBankTransactionTypeEnum::BANK_FEE;

            if ($isFee) {
                $newBalance = bcsub((string) $lockedAccount->current_balance, $normAmount, 2);
                $offsetAccount = $this->coaService->getAccountBySystemKey('BANK_ADMIN_FEE', $tenantId);
                $lines = [
                    new JournalLineDto(
                        accountId: (int) $offsetAccount->id,
                        debitAmount: $normAmount,
                        creditAmount: '0.00',
                        description: $description,
                        storeId: $lockedAccount->store_id
                    ),
                    new JournalLineDto(
                        accountId: (int) $lockedAccount->gl_account_id,
                        debitAmount: '0.00',
                        creditAmount: $normAmount,
                        description: $description,
                        storeId: $lockedAccount->store_id
                    ),
                ];
            } else {
                $newBalance = bcadd((string) $lockedAccount->current_balance, $normAmount, 2);
                $offsetAccount = $this->coaService->getAccountBySystemKey('INTEREST_INCOME', $tenantId);
                $lines = [
                    new JournalLineDto(
                        accountId: (int) $lockedAccount->gl_account_id,
                        debitAmount: $normAmount,
                        creditAmount: '0.00',
                        description: $description,
                        storeId: $lockedAccount->store_id
                    ),
                    new JournalLineDto(
                        accountId: (int) $offsetAccount->id,
                        debitAmount: '0.00',
                        creditAmount: $normAmount,
                        description: $description,
                        storeId: $lockedAccount->store_id
                    ),
                ];
            }

            $lockedAccount->current_balance = $newBalance;
            $lockedAccount->save();

            $txnNumber = ('CBT-ADJ-'.$lockedAccount->id.'-'.date('Ymd').'-'.Str::upper(Str::random(4)));

            $journal = $this->journalEntryService->createAndPostJournal(
                tenantId: $tenantId,
                storeId: $lockedAccount->store_id,
                postingDate: $dateStr,
                idempotencyKey: "journal:bank_adj:{$tenantId}:{$idempotencyBase}",
                lines: $lines,
                sourceModule: 'Finance',
                sourceType: 'bank_adjustment',
                sourceNumber: $txnNumber,
                notes: $description,
                actor: $actor
            );

            return CashBankTransaction::withoutTenantScope()->create([
                'tenant_id' => $tenantId,
                'store_id' => $lockedAccount->store_id,
                'cash_bank_account_id' => $lockedAccount->id,
                'journal_entry_id' => $journal->id,
                'transaction_number' => $txnNumber,
                'transaction_type' => $typeEnum,
                'transaction_date' => $dateStr,
                'amount' => $normAmount,
                'running_balance_after' => $newBalance,
                'description' => $description,
                'reference_number' => $referenceNumber,
                'idempotency_key' => "cbt:bank_adj:{$tenantId}:{$idempotencyBase}",
                'status' => CashBankTransactionStatusEnum::POSTED,
                'created_by_user_id' => $actor->id,
                'posted_by_user_id' => $actor->id,
            ]);
        });
    }

    /**
     * Membatalkan transaksi kas/bank dan membalik jurnal GL serta saldo rekening terkait.
     */
    public function voidTransaction(CashBankTransaction $transaction, User $actor, string $reason): CashBankTransaction
    {
        // Invarian FIN-M7-12: Transaksi yang sudah direkonsiliasi dilarang dibatalkan
        if ($transaction->is_reconciled) {
            throw new DomainException('FIN-M7-12: Transaksi yang sudah direkonsiliasi tidak dapat dibatalkan.');
        }

        if ($transaction->status !== CashBankTransactionStatusEnum::POSTED) {
            throw new DomainException("Hanya transaksi berstatus POSTED yang dapat dibatalkan. Status saat ini: {$transaction->status->value}.");
        }

        $tenantId = $transaction->tenant_id;

        return DB::transaction(function () use ($tenantId, $transaction, $actor, $reason) {
            $lockedTxn = CashBankTransaction::withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($transaction->id);

            $account = CashBankAccount::withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($lockedTxn->cash_bank_account_id);

            // Tentukan arah saldo pembalik:
            // Jika tipe transaksi awal menambah saldo -> pembalik mengurangi saldo
            // Jika tipe transaksi awal mengurangi saldo -> pembalik menambah saldo
            $increasesBalance = in_array($lockedTxn->transaction_type, [
                CashBankTransactionTypeEnum::RECEIPT,
                CashBankTransactionTypeEnum::TRANSFER_IN,
                CashBankTransactionTypeEnum::POS_SHIFT_DEPOSIT,
                CashBankTransactionTypeEnum::PG_SETTLEMENT,
                CashBankTransactionTypeEnum::BANK_INTEREST,
            ], true);

            if ($increasesBalance) {
                $account->current_balance = bcsub((string) $account->current_balance, (string) $lockedTxn->amount, 2);
            } else {
                $account->current_balance = bcadd((string) $account->current_balance, (string) $lockedTxn->amount, 2);
            }
            $account->save();

            $lockedTxn->status = CashBankTransactionStatusEnum::REVERSED;
            $lockedTxn->reversed_by_user_id = $actor->id;
            $lockedTxn->reversed_at = now();
            $lockedTxn->reversal_reason = $reason;
            $lockedTxn->save();

            // Jika transaksi berpasangan (transfer internal / setoran POS / settlement PG):
            if ($lockedTxn->transfer_pair_account_id) {
                $counterpartTxn = CashBankTransaction::withoutTenantScope()
                    ->where('tenant_id', $tenantId)
                    ->where('journal_entry_id', $lockedTxn->journal_entry_id)
                    ->where('id', '!=', $lockedTxn->id)
                    ->lockForUpdate()
                    ->first();

                if ($counterpartTxn && $counterpartTxn->status === CashBankTransactionStatusEnum::POSTED) {
                    $counterpartAccount = CashBankAccount::withoutTenantScope()
                        ->where('tenant_id', $tenantId)
                        ->lockForUpdate()
                        ->findOrFail($counterpartTxn->cash_bank_account_id);

                    $counterpartIncreases = in_array($counterpartTxn->transaction_type, [
                        CashBankTransactionTypeEnum::RECEIPT,
                        CashBankTransactionTypeEnum::TRANSFER_IN,
                        CashBankTransactionTypeEnum::POS_SHIFT_DEPOSIT,
                        CashBankTransactionTypeEnum::PG_SETTLEMENT,
                        CashBankTransactionTypeEnum::BANK_INTEREST,
                    ], true);

                    if ($counterpartIncreases) {
                        $counterpartAccount->current_balance = bcsub((string) $counterpartAccount->current_balance, (string) $counterpartTxn->amount, 2);
                    } else {
                        $counterpartAccount->current_balance = bcadd((string) $counterpartAccount->current_balance, (string) $counterpartTxn->amount, 2);
                    }
                    $counterpartAccount->save();

                    $counterpartTxn->status = CashBankTransactionStatusEnum::REVERSED;
                    $counterpartTxn->reversed_by_user_id = $actor->id;
                    $counterpartTxn->reversed_at = now();
                    $counterpartTxn->reversal_reason = "Pembalik berpasangan transaksi #{$lockedTxn->transaction_number}: {$reason}";
                    $counterpartTxn->save();
                }
            }

            // Balik Jurnal GL jika ada
            if ($lockedTxn->journal_entry_id) {
                $journal = $lockedTxn->journalEntry;
                if ($journal) {
                    $this->journalEntryService->reverseJournal(
                        $journal,
                        now()->toDateString(),
                        "Pembalik transaksi kas/bank #{$lockedTxn->transaction_number}: {$reason}",
                        $actor
                    );
                }
            }

            return $lockedTxn;
        });
    }
}
