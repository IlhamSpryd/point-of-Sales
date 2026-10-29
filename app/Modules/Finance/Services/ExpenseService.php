<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Models\User;
use App\Modules\Finance\DataTransferObjects\FinancialEventDto;
use App\Modules\Finance\Enums\CashBankTransactionStatusEnum;
use App\Modules\Finance\Enums\CashBankTransactionTypeEnum;
use App\Modules\Finance\Enums\ExpenseStatusEnum;
use App\Modules\Finance\Enums\TaxDirectionEnum;
use App\Modules\Finance\Models\CashBankAccount;
use App\Modules\Finance\Models\CashBankTransaction;
use App\Modules\Finance\Models\Expense;
use App\Modules\Finance\Models\ExpenseApproval;
use App\Modules\Finance\Models\ExpenseAttachment;
use App\Modules\Finance\Models\ExpenseCategory;
use App\Modules\Finance\Models\ExpenseLine;
use App\Modules\Finance\Models\TaxCode;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ExpenseService
{
    public function __construct(
        protected PostingEngineService $postingEngineService,
        protected JournalEntryService $journalEntryService,
        protected CashBankTransactionService $cashBankTxnService,
        protected TaxConfigurationService $taxConfigService,
        protected TaxLedgerService $taxLedgerService,
        protected ChartOfAccountService $coaService
    ) {}

    /**
     * Membuat draft pengajuan beban baru.
     *
     * @param  array<string, mixed>  $data
     */
    public function createDraft(array $data, User $actor): Expense
    {
        $tenantId = $actor->tenant_id;

        return DB::transaction(function () use ($tenantId, $data, $actor) {
            $categoryId = (int) ($data['category_id'] ?? 0);
            $category = ExpenseCategory::withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->where('id', $categoryId)
                ->firstOrFail();

            $date = $data['expense_date'] ?? now()->toDateString();
            $dateStr = $date instanceof CarbonInterface ? $date->toDateString() : Carbon::parse($date)->toDateString();

            $expenseNumber = $data['expense_number'] ?? ('EXP-'.$tenantId.'-'.date('Ymd').'-'.Str::upper(Str::random(5)));

            $linesData = $data['lines'] ?? [];
            if (empty($linesData)) {
                throw new DomainException('FIN-M7-01: Pengajuan beban wajib memiliki minimal 1 baris rincian.');
            }

            $subtotal = '0.00';
            $totalTax = '0.00';
            $processedLines = [];

            $lineNumber = 1;
            foreach ($linesData as $lineItem) {
                $lineAmount = bcadd((string) ($lineItem['amount'] ?? '0.00'), '0', 2);
                if (bccomp($lineAmount, '0.00', 2) <= 0) {
                    throw new DomainException("FIN-M7-01: Baris #{$lineNumber} nominal wajib lebih besar dari 0.00.");
                }

                $taxCodeId = ! empty($lineItem['tax_code_id']) ? (int) $lineItem['tax_code_id'] : null;
                $lineTaxAmount = '0.00';

                if ($taxCodeId) {
                    $taxCode = TaxCode::withoutTenantScope()->where('tenant_id', $tenantId)->find($taxCodeId);
                    if ($taxCode) {
                        $taxResult = $this->taxConfigService->calculateTax($taxCode, $lineAmount, $dateStr);
                        $lineTaxAmount = $taxResult['tax_amount'];
                    }
                }

                $subtotal = bcadd($subtotal, $lineAmount, 2);
                $totalTax = bcadd($totalTax, $lineTaxAmount, 2);

                $processedLines[] = [
                    'line_number' => $lineNumber++,
                    'description' => (string) ($lineItem['description'] ?? "Beban {$category->category_name}"),
                    'amount' => $lineAmount,
                    'tax_code_id' => $taxCodeId,
                    'tax_amount' => $lineTaxAmount,
                ];
            }

            $totalAmount = bcadd($subtotal, $totalTax, 2);

            $expense = Expense::withoutTenantScope()->create([
                'tenant_id' => $tenantId,
                'store_id' => ! empty($data['store_id']) ? (int) $data['store_id'] : null,
                'category_id' => $category->id,
                'expense_number' => $expenseNumber,
                'expense_date' => $dateStr,
                'subtotal_amount' => $subtotal,
                'tax_amount' => $totalTax,
                'total_amount' => $totalAmount,
                'description' => $data['description'] ?? null,
                'status' => ExpenseStatusEnum::DRAFT,
                'submitted_by_user_id' => $actor->id,
            ]);

            foreach ($processedLines as $pline) {
                ExpenseLine::withoutTenantScope()->create([
                    'tenant_id' => $tenantId,
                    'expense_id' => $expense->id,
                    'line_number' => $pline['line_number'],
                    'description' => $pline['description'],
                    'amount' => $pline['amount'],
                    'tax_code_id' => $pline['tax_code_id'],
                    'tax_amount' => $pline['tax_amount'],
                ]);
            }

            return $expense->load('lines', 'category');
        });
    }

    /**
     * Memperbarui draft beban.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateDraft(Expense $expense, array $data, User $actor): Expense
    {
        if ($expense->status !== ExpenseStatusEnum::DRAFT) {
            throw new DomainException("Hanya beban berstatus DRAFT yang dapat diperbarui. Status saat ini: {$expense->status->value}.");
        }

        $tenantId = $expense->tenant_id;

        return DB::transaction(function () use ($tenantId, $expense, $data) {
            if (! empty($data['category_id'])) {
                $category = ExpenseCategory::withoutTenantScope()
                    ->where('tenant_id', $tenantId)
                    ->where('id', (int) $data['category_id'])
                    ->firstOrFail();
                $expense->category_id = $category->id;
            }

            if (! empty($data['store_id'])) {
                $expense->store_id = (int) $data['store_id'];
            }

            if (! empty($data['expense_date'])) {
                $date = $data['expense_date'];
                $expense->expense_date = $date instanceof CarbonInterface ? $date->toDateString() : Carbon::parse($date)->toDateString();
            }

            if (isset($data['description'])) {
                $expense->description = $data['description'];
            }

            if (! empty($data['lines'])) {
                $expense->lines()->delete();

                $subtotal = '0.00';
                $totalTax = '0.00';
                $dateStr = $expense->expense_date instanceof CarbonInterface ? $expense->expense_date->toDateString() : (string) $expense->expense_date;

                $lineNumber = 1;
                foreach ($data['lines'] as $lineItem) {
                    $lineAmount = bcadd((string) ($lineItem['amount'] ?? '0.00'), '0', 2);
                    $taxCodeId = ! empty($lineItem['tax_code_id']) ? (int) $lineItem['tax_code_id'] : null;
                    $lineTaxAmount = '0.00';

                    if ($taxCodeId) {
                        $taxCode = TaxCode::withoutTenantScope()->where('tenant_id', $tenantId)->find($taxCodeId);
                        if ($taxCode) {
                            $taxResult = $this->taxConfigService->calculateTax($taxCode, $lineAmount, $dateStr);
                            $lineTaxAmount = $taxResult['tax_amount'];
                        }
                    }

                    $subtotal = bcadd($subtotal, $lineAmount, 2);
                    $totalTax = bcadd($totalTax, $lineTaxAmount, 2);

                    ExpenseLine::withoutTenantScope()->create([
                        'tenant_id' => $tenantId,
                        'expense_id' => $expense->id,
                        'line_number' => $lineNumber++,
                        'description' => (string) ($lineItem['description'] ?? 'Beban Operasional'),
                        'amount' => $lineAmount,
                        'tax_code_id' => $taxCodeId,
                        'tax_amount' => $lineTaxAmount,
                    ]);
                }

                $expense->subtotal_amount = $subtotal;
                $expense->tax_amount = $totalTax;
                $expense->total_amount = bcadd($subtotal, $totalTax, 2);
            }

            $expense->save();

            return $expense->fresh(['lines', 'category']);
        });
    }

    /**
     * Menghapus draft beban.
     */
    public function deleteDraft(Expense $expense, User $actor): bool
    {
        if ($expense->status !== ExpenseStatusEnum::DRAFT) {
            throw new DomainException("Hanya beban berstatus DRAFT yang dapat dihapus. Status saat ini: {$expense->status->value}.");
        }

        return DB::transaction(function () use ($expense) {
            $expense->lines()->delete();
            $expense->attachments()->delete();

            return (bool) $expense->delete();
        });
    }

    /**
     * Mengajukan beban untuk persetujuan (DRAFT -> SUBMITTED).
     */
    public function submit(Expense $expense, User $actor): Expense
    {
        if ($expense->status !== ExpenseStatusEnum::DRAFT) {
            throw new DomainException("Hanya beban berstatus DRAFT yang dapat diajukan. Status saat ini: {$expense->status->value}.");
        }

        $expense->status = ExpenseStatusEnum::SUBMITTED;
        $expense->submitted_by_user_id = $actor->id;
        $expense->submitted_at = now();
        $expense->save();

        return $expense;
    }

    /**
     * Menyetujui beban operasional (SUBMITTED -> APPROVED).
     * Menerapkan Separation of Duties (SoD): Pengaju dilarang menyetujui bebannya sendiri.
     * Memposting jurnal akrual (PR-EXP-01): Debit Beban, Debit PPN Masukan, Kredit Beban Akrual.
     */
    public function approve(Expense $expense, User $actor, ?string $notes = null): Expense
    {
        if ($expense->status !== ExpenseStatusEnum::SUBMITTED) {
            throw new DomainException("Hanya beban berstatus SUBMITTED yang dapat disetujui. Status saat ini: {$expense->status->value}.");
        }

        // SoD Enforce
        if ($expense->submitted_by_user_id === $actor->id) {
            throw new DomainException('FIN-M7-06: Pelanggaran Separation of Duties (SoD) - Pengaju beban tidak dapat menyetujui bebannya sendiri.');
        }

        return DB::transaction(function () use ($expense, $actor, $notes) {
            $tenantId = $expense->tenant_id;
            $dateStr = $expense->expense_date instanceof CarbonInterface ? $expense->expense_date->toDateString() : (string) $expense->expense_date;

            // Catat log persetujuan
            ExpenseApproval::withoutTenantScope()->create([
                'tenant_id' => $tenantId,
                'expense_id' => $expense->id,
                'approver_user_id' => $actor->id,
                'action' => 'APPROVED',
                'notes' => $notes,
            ]);

            // Ambil kode pajak pertama jika ada untuk dynamic mapper
            $firstTaxCodeId = null;
            foreach ($expense->lines as $line) {
                if ($line->tax_code_id) {
                    $firstTaxCodeId = $line->tax_code_id;
                    break;
                }
            }

            // Posting jurnal akrual via Posting Engine
            $eventDto = new FinancialEventDto(
                tenantId: $tenantId,
                storeId: $expense->store_id,
                sourceModule: 'FINANCE',
                sourceType: 'EXPENSE',
                sourceId: $expense->id,
                sourceDocumentNumber: $expense->expense_number,
                eventCode: 'expense.approved',
                idempotencyKey: "expense:accrual:{$tenantId}:{$expense->id}",
                occurredAt: now(),
                accountingDate: $dateStr,
                payload: [
                    'expense_category_id' => $expense->category_id,
                    'subtotal_amount' => $expense->subtotal_amount,
                    'tax_amount' => $expense->tax_amount,
                    'total_amount' => $expense->total_amount,
                    'expense_number' => $expense->expense_number,
                    'tax_code_id' => $firstTaxCodeId,
                ],
                userId: $actor->id
            );

            $journal = $this->postingEngineService->postEvent($eventDto);

            // Jika ada pajak, catat ke tax_ledger_entries
            if (bccomp((string) $expense->tax_amount, '0.00', 2) > 0 && $firstTaxCodeId) {
                $this->taxLedgerService->recordTaxEntry([
                    'tenant_id' => $tenantId,
                    'tax_code_id' => $firstTaxCodeId,
                    'tax_direction' => TaxDirectionEnum::INPUT_TAX,
                    'transaction_date' => $dateStr,
                    'source_document_type' => 'EXPENSE',
                    'source_document_id' => $expense->id,
                    'source_document_number' => $expense->expense_number,
                    'tax_base_amount' => $expense->subtotal_amount,
                    'tax_amount' => $expense->tax_amount,
                    'journal_entry_id' => $journal->id,
                ], $actor);
            }

            $expense->status = ExpenseStatusEnum::APPROVED;
            $expense->approved_by_user_id = $actor->id;
            $expense->approved_at = now();
            $expense->accrual_journal_id = $journal->id;
            $expense->save();

            return $expense->fresh(['lines', 'category', 'accrualJournal']);
        });
    }

    /**
     * Menolak pengajuan beban (SUBMITTED -> REJECTED).
     */
    public function reject(Expense $expense, User $actor, string $reason): Expense
    {
        if ($expense->status !== ExpenseStatusEnum::SUBMITTED) {
            throw new DomainException("Hanya beban berstatus SUBMITTED yang dapat ditolak. Status saat ini: {$expense->status->value}.");
        }

        if ($expense->submitted_by_user_id === $actor->id) {
            throw new DomainException('FIN-M7-06: Pelanggaran Separation of Duties (SoD) - Pengaju beban tidak dapat menolak bebannya sendiri.');
        }

        if (trim($reason) === '') {
            throw new DomainException('Alasan penolakan beban wajib diisi.');
        }

        return DB::transaction(function () use ($expense, $actor, $reason) {
            ExpenseApproval::withoutTenantScope()->create([
                'tenant_id' => $expense->tenant_id,
                'expense_id' => $expense->id,
                'approver_user_id' => $actor->id,
                'action' => 'REJECTED',
                'notes' => $reason,
            ]);

            $expense->status = ExpenseStatusEnum::REJECTED;
            $expense->rejection_reason = $reason;
            $expense->save();

            return $expense;
        });
    }

    /**
     * Mencatat pelunasan pembayaran beban (APPROVED -> PAID).
     * Memotong saldo akun Kas/Bank secara atomik dengan validasi saldo.
     * Memposting jurnal pelunasan (PR-EXP-02): Debit Beban Akrual, Kredit Kas/Bank.
     */
    public function recordPayment(
        Expense $expense,
        CashBankAccount $paymentAccount,
        CarbonInterface|string $date,
        User $actor
    ): Expense {
        if ($expense->status !== ExpenseStatusEnum::APPROVED) {
            throw new DomainException("Hanya beban berstatus APPROVED yang dapat dibayarkan. Status saat ini: {$expense->status->value}.");
        }

        if ($expense->tenant_id !== $paymentAccount->tenant_id) {
            throw new DomainException('Akun kas/bank tidak berada dalam tenant yang sama.');
        }

        $dateStr = $date instanceof CarbonInterface ? $date->toDateString() : Carbon::parse($date)->toDateString();
        $amount = (string) $expense->total_amount;

        return DB::transaction(function () use ($expense, $paymentAccount, $dateStr, $amount, $actor) {
            $tenantId = $expense->tenant_id;

            // Kunci akun kas/bank dan periksa saldo
            $lockedAccount = CashBankAccount::withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($paymentAccount->id);

            if (bccomp((string) $lockedAccount->current_balance, $amount, 2) < 0) {
                throw new DomainException("FIN-M7-05: Saldo akun kas/bank '{$lockedAccount->account_name}' ({$lockedAccount->current_balance}) tidak mencukupi untuk pembayaran beban sebesar {$amount}.");
            }

            // Kurangi saldo akun kas/bank
            $newBalance = bcsub((string) $lockedAccount->current_balance, $amount, 2);
            $lockedAccount->current_balance = $newBalance;
            $lockedAccount->save();

            // Catat mutasi di cash_bank_transactions
            $txnNumber = 'CBT-EXP-'.$lockedAccount->id.'-'.date('Ymd').'-'.Str::upper(Str::random(4));
            $cbt = CashBankTransaction::withoutTenantScope()->create([
                'tenant_id' => $tenantId,
                'store_id' => $expense->store_id ?? $lockedAccount->store_id,
                'cash_bank_account_id' => $lockedAccount->id,
                'transaction_number' => $txnNumber,
                'transaction_date' => $dateStr,
                'transaction_type' => CashBankTransactionTypeEnum::DISBURSEMENT,
                'amount' => $amount,
                'running_balance_after' => $newBalance,
                'reference_number' => $expense->expense_number,
                'description' => "Pembayaran Beban Operasional: {$expense->expense_number}",
                'status' => CashBankTransactionStatusEnum::POSTED,
                'idempotency_key' => "cbt:exp:{$tenantId}:{$expense->id}",
            ]);

            // Posting jurnal pelunasan via Posting Engine (PR-EXP-02)
            $eventDto = new FinancialEventDto(
                tenantId: $tenantId,
                storeId: $expense->store_id,
                sourceModule: 'FINANCE',
                sourceType: 'EXPENSE_PAYMENT',
                sourceId: $expense->id,
                sourceDocumentNumber: $expense->expense_number,
                eventCode: 'expense.paid',
                idempotencyKey: "expense:payment:{$tenantId}:{$expense->id}",
                occurredAt: now(),
                accountingDate: $dateStr,
                payload: [
                    'total_amount' => $amount,
                    'expense_number' => $expense->expense_number,
                    'cash_bank_account_id' => $lockedAccount->id,
                    'source_cash_bank_id' => $lockedAccount->id,
                ],
                userId: $actor->id
            );

            $journal = $this->postingEngineService->postEvent($eventDto);

            // Kaitkan jurnal ke cash bank transaction
            $cbt->journal_entry_id = $journal->id;
            $cbt->save();

            $expense->status = ExpenseStatusEnum::PAID;
            $expense->cash_bank_account_id = $lockedAccount->id;
            $expense->cash_bank_transaction_id = $cbt->id;
            $expense->payment_journal_id = $journal->id;
            $expense->paid_at = Carbon::parse($dateStr);
            $expense->save();

            return $expense->fresh(['lines', 'category', 'cashBankAccount', 'cashBankTransaction', 'paymentJournal']);
        });
    }

    /**
     * Membatalkan (void) beban.
     * Membalikkan seluruh jurnal yang sudah terbentuk (akrual dan/atau pembayaran) secara atomik.
     * Mengembalikan saldo kas/bank jika pembayaran sudah pernah dilakukan.
     */
    public function void(Expense $expense, User $actor, string $reason): Expense
    {
        if (in_array($expense->status, [ExpenseStatusEnum::DRAFT, ExpenseStatusEnum::REJECTED, ExpenseStatusEnum::VOIDED], true)) {
            throw new DomainException("Beban dengan status {$expense->status->value} tidak dapat dibatalkan (void).");
        }

        return DB::transaction(function () use ($expense, $actor, $reason) {
            $now = now();
            $tenantId = $expense->tenant_id;

            // 1. Jika sudah dibayar, balikkan jurnal pembayaran dan kembalikan saldo kas
            if ($expense->payment_journal_id && $expense->cash_bank_account_id) {
                $paymentJournal = $expense->paymentJournal;
                if ($paymentJournal && $paymentJournal->reversed_by_journal_id === null) {
                    $this->journalEntryService->reverseJournal(
                        $paymentJournal,
                        $now,
                        "Reversal pembayaran beban void #{$expense->expense_number}: {$reason}",
                        $actor
                    );
                }

                // Kembalikan saldo kas/bank
                $lockedAccount = CashBankAccount::withoutTenantScope()
                    ->where('tenant_id', $tenantId)
                    ->lockForUpdate()
                    ->findOrFail($expense->cash_bank_account_id);

                $lockedAccount->current_balance = bcadd(
                    (string) $lockedAccount->current_balance,
                    (string) $expense->total_amount,
                    2
                );
                $lockedAccount->save();

                // Catat mutasi pembalik di CBT
                CashBankTransaction::withoutTenantScope()->create([
                    'tenant_id' => $tenantId,
                    'store_id' => $expense->store_id ?? $lockedAccount->store_id,
                    'cash_bank_account_id' => $lockedAccount->id,
                    'transaction_number' => 'CBT-VOID-'.$lockedAccount->id.'-'.date('Ymd').'-'.Str::upper(Str::random(4)),
                    'transaction_date' => $now->toDateString(),
                    'transaction_type' => CashBankTransactionTypeEnum::RECEIPT,
                    'amount' => (string) $expense->total_amount,
                    'running_balance_after' => (string) $lockedAccount->current_balance,
                    'reference_number' => $expense->expense_number,
                    'description' => "Pengembalian Saldo atas Pembatalan Beban #{$expense->expense_number}",
                    'status' => CashBankTransactionStatusEnum::POSTED,
                    'idempotency_key' => "cbt:void:{$tenantId}:{$expense->id}",
                ]);
            }

            // 2. Balikkan jurnal akrual jika ada
            if ($expense->accrual_journal_id) {
                $accrualJournal = $expense->accrualJournal;
                if ($accrualJournal && $accrualJournal->reversed_by_journal_id === null) {
                    $this->journalEntryService->reverseJournal(
                        $accrualJournal,
                        $now,
                        "Reversal akrual beban void #{$expense->expense_number}: {$reason}",
                        $actor
                    );
                }
            }

            $expense->status = ExpenseStatusEnum::VOIDED;
            $expense->rejection_reason = $reason;
            $expense->save();

            return $expense->fresh();
        });
    }

    /**
     * Menambahkan bukti lampiran file pada beban.
     *
     * @param  array<string, mixed>  $fileData
     */
    public function addAttachment(Expense $expense, array $fileData, User $actor): ExpenseAttachment
    {
        return ExpenseAttachment::withoutTenantScope()->create([
            'tenant_id' => $expense->tenant_id,
            'expense_id' => $expense->id,
            'file_path' => $fileData['file_path'],
            'file_name' => $fileData['file_name'],
            'file_size' => (int) ($fileData['file_size'] ?? 0),
            'mime_type' => $fileData['mime_type'] ?? null,
        ]);
    }
}
