<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Enums\InvoiceSettlementStatusEnum;
use App\Models\User;
use App\Modules\Finance\DataTransferObjects\FinancialEventDto;
use App\Modules\Finance\Enums\ApEntryTypeEnum;
use App\Modules\Finance\Enums\ApPaymentAllocationStatusEnum;
use App\Modules\Finance\Enums\ApPaymentStatusEnum;
use App\Modules\Finance\Enums\CashBankTransactionStatusEnum;
use App\Modules\Finance\Enums\CashBankTransactionTypeEnum;
use App\Modules\Finance\Models\ApLedgerEntry;
use App\Modules\Finance\Models\ApPayment;
use App\Modules\Finance\Models\ApPaymentAllocation;
use App\Modules\Finance\Models\CashBankAccount;
use App\Modules\Finance\Models\CashBankTransaction;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Purchasing\Models\SupplierInvoice;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ApPaymentService
{
    public function __construct(
        protected PostingEngineService $postingEngine,
        protected JournalEntryService $journalEntryService
    ) {}

    /**
     * Membuat draft pembayaran hutang ke pemasok.
     *
     * @param  array<string, mixed>  $data
     */
    public function createDraft(array $data, User $actor): ApPayment
    {
        $tenantId = (int) ($data['tenant_id'] ?? $actor->tenant_id);
        $storeId = (int) $data['store_id'];
        $supplierId = (int) $data['supplier_id'];
        $cashBankAccountId = (int) $data['cash_bank_account_id'];
        $totalPaymentAmount = (string) $data['total_payment_amount'];
        $paymentDate = $data['payment_date'] ?? now()->toDateString();

        if (bccomp($totalPaymentAmount, '0.00', 2) <= 0) {
            throw ValidationException::withMessages([
                'total_payment_amount' => ['FIN-M7-01: Total pembayaran harus lebih besar dari 0.00.'],
            ]);
        }

        $paymentNumber = $data['payment_number'] ?? ('PAY-AP-'.$storeId.'-'.date('Ymd').'-'.strtoupper(Str::random(6)));
        $idempotencyKey = $data['idempotency_key'] ?? ("appay:draft:{$tenantId}:".Str::uuid());

        return ApPayment::create([
            'tenant_id' => $tenantId,
            'store_id' => $storeId,
            'supplier_id' => $supplierId,
            'cash_bank_account_id' => $cashBankAccountId,
            'payment_number' => $paymentNumber,
            'payment_date' => $paymentDate,
            'payment_method' => $data['payment_method'] ?? 'BANK_TRANSFER',
            'currency_code' => $data['currency_code'] ?? 'IDR',
            'exchange_rate' => $data['exchange_rate'] ?? '1.000000',
            'total_payment_amount' => $totalPaymentAmount,
            'allocated_amount' => '0.00',
            'unapplied_prepayment_amount' => '0.00',
            'early_discount_amount' => (string) ($data['early_discount_amount'] ?? '0.00'),
            'bank_fee_amount' => (string) ($data['bank_fee_amount'] ?? '0.00'),
            'reference_number' => $data['reference_number'] ?? null,
            'status' => ApPaymentStatusEnum::DRAFT,
            'notes' => $data['notes'] ?? null,
            'idempotency_key' => $idempotencyKey,
            'created_by_user_id' => $actor->id,
        ]);
    }

    /**
     * Mengajukan pembayaran untuk persetujuan.
     */
    public function submit(ApPayment $payment, User $actor): ApPayment
    {
        if (! $payment->isDraft()) {
            throw new DomainException("Hanya pembayaran berstatus DRAFT yang dapat diajukan. Status saat ini: {$payment->status->value}.");
        }

        $payment->status = ApPaymentStatusEnum::PENDING_APPROVAL;
        $payment->save();

        return $payment;
    }

    /**
     * Menyetujui pembayaran dengan kontrol Segregation of Duties (SoD).
     */
    public function approve(ApPayment $payment, User $actor): ApPayment
    {
        if (! in_array($payment->status, [ApPaymentStatusEnum::DRAFT, ApPaymentStatusEnum::PENDING_APPROVAL], true)) {
            throw new DomainException("Pembayaran dengan status {$payment->status->value} tidak dapat disetujui.");
        }

        // Kontrol Separation of Duties (SoD) FIN-M7-10
        if ($payment->created_by_user_id === $actor->id && ! $actor->is_admin) {
            throw new DomainException('FIN-M7-10: Pembuat draft pembayaran supplier dilarang menyetujui pembayarannya sendiri (Separation of Duties).');
        }

        $payment->status = ApPaymentStatusEnum::PENDING_APPROVAL;
        $payment->approved_by_user_id = $actor->id;
        $payment->approved_at = now();
        $payment->save();

        return $payment;
    }

    /**
     * Memposting pembayaran supplier:
     * 1. Kunci supplier_invoices dalam urutan id ASC (anti-deadlock).
     * 2. Validasi allocated_amount <= outstanding_amount (anti-overpayment).
     * 3. Perbarui paid_amount, outstanding_amount, settlement_status pada supplier_invoices.
     * 4. Catat baris PAYMENT_DEBIT di ap_ledger_entries.
     * 5. Catat pengeluaran di cash_bank_transactions (DISBURSEMENT).
     * 6. Posting Jurnal GL PR-AP-01 via PostingEngineService.
     *
     * @param  array<int, array{supplier_invoice_id: int, allocated_amount: string, discount_taken_amount?: string}>  $allocationsData
     */
    public function postPayment(ApPayment $payment, array $allocationsData, User $actor): ApPayment
    {
        $tenantId = (int) $payment->tenant_id;

        return DB::transaction(function () use ($payment, $allocationsData, $actor, $tenantId) {
            /** @var ApPayment $lockedPayment */
            $lockedPayment = ApPayment::withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($payment->id);

            if ($lockedPayment->isPosted()) {
                return $lockedPayment;
            }

            if ($lockedPayment->isVoided()) {
                throw new DomainException('Pembayaran yang sudah dibatalkan (VOIDED) tidak dapat diposting.');
            }

            // 1. Ambil dan urutkan ID faktur secara deterministik (id ASC) untuk mencegah deadlock
            $invoiceIds = array_unique(array_map(fn ($a) => (int) $a['supplier_invoice_id'], $allocationsData));
            sort($invoiceIds);

            // 2. Kunci faktur yang akan dialokasikan
            $invoices = SupplierInvoice::withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $invoiceIds)
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $totalAllocated = '0.00';

            // 3. Validasi alokasi & perbarui saldo faktur
            foreach ($allocationsData as $alloc) {
                $invId = (int) $alloc['supplier_invoice_id'];
                $allocAmount = (string) $alloc['allocated_amount'];
                $discountTaken = (string) ($alloc['discount_taken_amount'] ?? '0.00');

                if (bccomp($allocAmount, '0.00', 2) <= 0) {
                    throw ValidationException::withMessages([
                        'allocations' => ['FIN-M7-01: Jumlah alokasi pada setiap faktur harus lebih besar dari 0.00.'],
                    ]);
                }

                /** @var SupplierInvoice|null $invoice */
                $invoice = $invoices->get($invId);
                if (! $invoice) {
                    throw new DomainException("Faktur Pemasok #{$invId} tidak ditemukan untuk tenant ini.");
                }

                // Cek overpayment terhadap sisa tagihan faktur pemasok (FIN-M7-11)
                if (bccomp($allocAmount, (string) $invoice->outstanding_amount, 2) > 0) {
                    throw ValidationException::withMessages([
                        'allocations' => [
                            "FIN-M7-11: Alokasi pembayaran ({$allocAmount}) melebihi sisa tagihan faktur #{$invoice->internal_invoice_number} ({$invoice->outstanding_amount}).",
                        ],
                    ]);
                }

                // Perbarui akumulator faktur
                $newPaid = bcadd((string) $invoice->paid_amount, $allocAmount, 2);
                $newOutstanding = bcsub((string) $invoice->outstanding_amount, $allocAmount, 2);

                $invoice->paid_amount = $newPaid;
                $invoice->outstanding_amount = $newOutstanding;
                $invoice->settlement_status = (bccomp($newOutstanding, '0.00', 2) === 0)
                    ? InvoiceSettlementStatusEnum::Paid
                    : InvoiceSettlementStatusEnum::PartiallyPaid;
                $invoice->save();

                // Catat ApPaymentAllocation
                ApPaymentAllocation::withoutTenantScope()->create([
                    'tenant_id' => $tenantId,
                    'store_id' => $lockedPayment->store_id,
                    'ap_payment_id' => $lockedPayment->id,
                    'supplier_invoice_id' => $invoice->id,
                    'allocated_amount' => $allocAmount,
                    'discount_taken_amount' => $discountTaken,
                    'status' => ApPaymentAllocationStatusEnum::POSTED,
                    'idempotency_key' => "appa:alloc:{$tenantId}:{$lockedPayment->id}:{$invoice->id}",
                ]);

                $totalAllocated = bcadd($totalAllocated, $allocAmount, 2);
            }

            // 4. Hitung porsi prepayment / kelebihan bayar
            if (bccomp($totalAllocated, (string) $lockedPayment->total_payment_amount, 2) > 0) {
                throw ValidationException::withMessages([
                    'allocations' => ['Total alokasi melebihi total nominal pembayaran yang dikeluarkan.'],
                ]);
            }

            $unappliedPrepayment = bcsub((string) $lockedPayment->total_payment_amount, $totalAllocated, 2);

            // 5. Kunci dan perbarui running balance subledger AP (PAYMENT_DEBIT)
            $latestEntry = ApLedgerEntry::withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->where('supplier_id', $lockedPayment->supplier_id)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $prevBalance = $latestEntry ? (string) $latestEntry->running_balance : '0.00';
            $newBalance = bcsub($prevBalance, $totalAllocated, 2);

            $ledgerEntry = ApLedgerEntry::withoutTenantScope()->create([
                'tenant_id' => $tenantId,
                'store_id' => $lockedPayment->store_id,
                'supplier_id' => $lockedPayment->supplier_id,
                'ap_payment_id' => $lockedPayment->id,
                'entry_number' => $lockedPayment->payment_number,
                'effective_date' => $lockedPayment->payment_date->toDateString(),
                'entry_type' => ApEntryTypeEnum::PAYMENT_DEBIT,
                'debit_amount' => $totalAllocated,
                'credit_amount' => '0.00',
                'running_balance' => $newBalance,
                'idempotency_key' => "aple:pay:{$tenantId}:{$lockedPayment->id}",
                'description' => "Pembayaran Hutang Pemasok #{$lockedPayment->payment_number}",
                'created_by_user_id' => $actor->id,
            ]);

            // 6. Mutasi Kas & Bank (DISBURSEMENT)
            /** @var CashBankAccount $account */
            $account = CashBankAccount::withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($lockedPayment->cash_bank_account_id);

            $newAccountBalance = bcsub((string) $account->current_balance, (string) $lockedPayment->total_payment_amount, 2);
            $account->current_balance = $newAccountBalance;
            $account->save();

            $cashBankTxn = CashBankTransaction::withoutTenantScope()->create([
                'tenant_id' => $tenantId,
                'store_id' => $lockedPayment->store_id,
                'cash_bank_account_id' => $account->id,
                'transaction_number' => "CBT-AP-{$lockedPayment->id}",
                'transaction_type' => CashBankTransactionTypeEnum::DISBURSEMENT,
                'transaction_date' => $lockedPayment->payment_date->toDateString(),
                'amount' => (string) $lockedPayment->total_payment_amount,
                'running_balance_after' => $newAccountBalance,
                'description' => "Pengeluaran Pembayaran Hutang Supplier #{$lockedPayment->payment_number}",
                'reference_number' => $lockedPayment->payment_number,
                'idempotency_key' => "cbt:appay:{$tenantId}:{$lockedPayment->id}",
                'status' => CashBankTransactionStatusEnum::POSTED,
                'created_by_user_id' => $actor->id,
                'posted_by_user_id' => $actor->id,
            ]);

            // 7. Posting Jurnal GL PR-AP-01 via PostingEngineService
            $eventDto = new FinancialEventDto(
                tenantId: $tenantId,
                storeId: $lockedPayment->store_id,
                sourceModule: 'Finance',
                sourceType: 'ap_payment',
                sourceId: $lockedPayment->id,
                sourceDocumentNumber: $lockedPayment->payment_number,
                eventCode: 'ap.payment.posted',
                idempotencyKey: "m7:ap_payment_posted:{$tenantId}:{$lockedPayment->id}",
                occurredAt: now(),
                accountingDate: $lockedPayment->payment_date->toDateString(),
                payload: [
                    'allocated_amount' => $totalAllocated,
                    'unapplied_prepayment_amount' => $unappliedPrepayment,
                    'bank_fee_amount' => (string) $lockedPayment->bank_fee_amount,
                    'early_discount_amount' => (string) $lockedPayment->early_discount_amount,
                    'total_payment_amount' => (string) $lockedPayment->total_payment_amount,
                    'cash_bank_account_id' => $lockedPayment->cash_bank_account_id,
                    'supplier_id' => $lockedPayment->supplier_id,
                ],
                userId: $actor->id,
            );

            $journal = $this->postingEngine->postEvent($eventDto);

            // 8. Tautkan journal_entry_id
            $lockedPayment->journal_entry_id = $journal->id;
            $lockedPayment->status = ApPaymentStatusEnum::POSTED;
            $lockedPayment->posted_by_user_id = $actor->id;
            $lockedPayment->posted_at = now();
            $lockedPayment->allocated_amount = $totalAllocated;
            $lockedPayment->unapplied_prepayment_amount = $unappliedPrepayment;
            $lockedPayment->save();

            $ledgerEntry->journal_entry_id = $journal->id;
            $ledgerEntry->save();

            $cashBankTxn->journal_entry_id = $journal->id;
            $cashBankTxn->save();

            return $lockedPayment->fresh(['allocations', 'journalEntry']);
        });
    }

    /**
     * Membatalkan (Void) pembayaran supplier yang telah diposting.
     */
    public function voidPayment(ApPayment $payment, string $reason, User $actor): ApPayment
    {
        $tenantId = (int) $payment->tenant_id;

        return DB::transaction(function () use ($payment, $reason, $actor, $tenantId) {
            /** @var ApPayment $lockedPayment */
            $lockedPayment = ApPayment::withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($payment->id);

            if (! $lockedPayment->isPosted()) {
                throw new DomainException("Hanya pembayaran berstatus POSTED yang dapat dibatalkan (VOIDED). Status: {$lockedPayment->status->value}.");
            }

            // Periksa rekonsiliasi kas/bank
            $cbt = CashBankTransaction::withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->where('idempotency_key', "cbt:appay:{$tenantId}:{$lockedPayment->id}")
                ->first();

            if ($cbt && $cbt->is_reconciled) {
                throw new DomainException('FIN-M7-08: Pembayaran yang mutasi banknya telah direkonsiliasi tidak dapat dibatalkan.');
            }

            // 1. Buka kembali alokasi pada supplier_invoices
            $allocations = $lockedPayment->allocations()->get();
            foreach ($allocations as $alloc) {
                /** @var SupplierInvoice|null $invoice */
                $invoice = SupplierInvoice::withoutTenantScope()
                    ->where('tenant_id', $tenantId)
                    ->lockForUpdate()
                    ->find($alloc->supplier_invoice_id);

                if ($invoice) {
                    $invoice->paid_amount = bcsub((string) $invoice->paid_amount, (string) $alloc->allocated_amount, 2);
                    $invoice->outstanding_amount = bcadd((string) $invoice->outstanding_amount, (string) $alloc->allocated_amount, 2);
                    $invoice->settlement_status = (bccomp((string) $invoice->paid_amount, '0.00', 2) === 0)
                        ? InvoiceSettlementStatusEnum::Unpaid
                        : InvoiceSettlementStatusEnum::PartiallyPaid;
                    $invoice->save();
                }

                $alloc->status = ApPaymentAllocationStatusEnum::REVERSED;
                $alloc->save();
            }

            // 2. Reverse Jurnal GL
            if ($lockedPayment->journal_entry_id) {
                /** @var JournalEntry|null $originalJournal */
                $originalJournal = JournalEntry::withoutTenantScope()
                    ->where('tenant_id', $tenantId)
                    ->find($lockedPayment->journal_entry_id);

                if ($originalJournal) {
                    $this->journalEntryService->reverseJournal(
                        originalJournal: $originalJournal,
                        reversalDate: now()->toDateString(),
                        reason: "Pembatalan Pembayaran Hutang Pemasok #{$lockedPayment->payment_number}: {$reason}",
                        actor: $actor
                    );
                }
            }

            // 3. Kembalikan saldo Kas & Bank
            /** @var CashBankAccount $account */
            $account = CashBankAccount::withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($lockedPayment->cash_bank_account_id);

            $account->current_balance = bcadd((string) $account->current_balance, (string) $lockedPayment->total_payment_amount, 2);
            $account->save();

            if ($cbt) {
                $cbt->status = CashBankTransactionStatusEnum::REVERSED;
                $cbt->reversed_by_user_id = $actor->id;
                $cbt->reversed_at = now();
                $cbt->reversal_reason = $reason;
                $cbt->save();
            }

            // 4. Catat REVERSAL di ap_ledger_entries
            $latestEntry = ApLedgerEntry::withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->where('supplier_id', $lockedPayment->supplier_id)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $prevBalance = $latestEntry ? (string) $latestEntry->running_balance : '0.00';
            $newBalance = bcadd($prevBalance, (string) $lockedPayment->allocated_amount, 2);

            ApLedgerEntry::withoutTenantScope()->create([
                'tenant_id' => $tenantId,
                'store_id' => $lockedPayment->store_id,
                'supplier_id' => $lockedPayment->supplier_id,
                'ap_payment_id' => $lockedPayment->id,
                'entry_number' => "REV-{$lockedPayment->payment_number}",
                'effective_date' => now()->toDateString(),
                'entry_type' => ApEntryTypeEnum::REVERSAL,
                'debit_amount' => '0.00',
                'credit_amount' => (string) $lockedPayment->allocated_amount,
                'running_balance' => $newBalance,
                'idempotency_key' => "aple:rev:{$tenantId}:{$lockedPayment->id}",
                'description' => "Pembalik Pembayaran Supplier #{$lockedPayment->payment_number}: {$reason}",
                'created_by_user_id' => $actor->id,
            ]);

            // 5. Tandai status VOIDED
            $lockedPayment->status = ApPaymentStatusEnum::VOIDED;
            $lockedPayment->voided_by_user_id = $actor->id;
            $lockedPayment->voided_at = now();
            $lockedPayment->void_reason = $reason;
            $lockedPayment->save();

            return $lockedPayment->fresh();
        });
    }
}
