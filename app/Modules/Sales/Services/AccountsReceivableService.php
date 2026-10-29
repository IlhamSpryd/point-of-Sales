<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Modules\Customers\Models\CustomerCreditAccount;
use App\Modules\Customers\Services\CustomerCreditService;
use App\Modules\Finance\Models\ArReceipt;
use App\Modules\Purchasing\Services\DocumentSequenceService;
use App\Modules\Sales\Enums\ArLedgerEntryTypeEnum;
use App\Modules\Sales\Enums\CustomerInvoiceStatusEnum;
use App\Modules\Sales\Enums\SalesOrderInvoicingStatusEnum;
use App\Modules\Sales\Models\AccountingReceivableOutbox;
use App\Modules\Sales\Models\ArLedgerEntry;
use App\Modules\Sales\Models\ArPaymentAllocation;
use App\Modules\Sales\Models\CustomerInvoice;
use App\Modules\Sales\Models\CustomerInvoiceLine;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Settings\Services\AuditTrailService;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountsReceivableService
{
    public function __construct(
        protected TenantContext $tenantContext,
        protected DocumentSequenceService $sequenceService,
        protected AuditTrailService $auditTrailService,
        protected CustomerCreditService $creditService,
    ) {}

    /**
     * Buat Draft atau Langsung Posting Faktur Penjualan (Customer Invoice) dari Sales Order atau Data Manual.
     *
     * @param  SalesOrder|array<string, mixed>  $source
     * @param  array<string, mixed>  $options
     */
    public function createInvoice(
        SalesOrder|array $source,
        array $options,
        User $actor,
        ?string $idempotencyKey = null
    ): CustomerInvoice {
        return DB::transaction(function () use ($source, $options, $actor, $idempotencyKey) {
            $tenantId = (int) ($actor->tenant_id ?: $this->tenantContext->requireTenantId());

            if ($idempotencyKey !== null) {
                $existing = CustomerInvoice::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing) {
                    return $existing->load(['lines', 'customer']);
                }
            }

            if ($source instanceof SalesOrder) {
                return $this->createInvoiceFromSalesOrder($source, $options, $actor, $idempotencyKey);
            }

            return $this->createDirectInvoice($source, $options, $actor, $idempotencyKey);
        });
    }

    /**
     * Posting Customer Invoice (DRAFT -> POSTED):
     * - Mengunci baris CustomerCreditAccount (A0/A8 Step 2)
     * - Memindahkan nilai dari reserved_exposure ke invoiced_exposure (tanpa hitung-ganda)
     * - Menulis ArLedgerEntry (INVOICE_DEBIT)
     * - Menulis AccountingReceivableOutbox (INVOICE_POSTED)
     */
    public function postInvoice(CustomerInvoice $invoice, User $actor): CustomerInvoice
    {
        return DB::transaction(function () use ($invoice, $actor) {
            $tenantId = (int) $invoice->tenant_id;

            // 1. Kunci invoice
            $lockedInvoice = CustomerInvoice::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('id', $invoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedInvoice->status !== CustomerInvoiceStatusEnum::DRAFT) {
                throw new DomainException("Hanya faktur berstatus DRAFT yang dapat diposting. Status saat ini: {$lockedInvoice->status->value}");
            }

            // 2. Kunci Customer Credit Account (Lock Order Step 2 A0/A8)
            $creditAccount = CustomerCreditAccount::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('customer_id', $lockedInvoice->customer_id)
                ->lockForUpdate()
                ->first();

            if (! $creditAccount) {
                $customer = Customer::withoutGlobalScopes()->findOrFail($lockedInvoice->customer_id);
                $creditAccount = $this->creditService->getOrCreateCreditAccount($customer);
                $creditAccount = CustomerCreditAccount::withoutGlobalScopes()
                    ->where('id', $creditAccount->id)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            $invoiceTotal = (string) $lockedInvoice->total_amount;

            // 3. Atomik Pemindahan Eksposur (Transfer Exposure)
            // Jika ditautkan dari Sales Order, kurangi reserved_exposure sebesar nilai invoice, tambahkan ke invoiced_exposure
            if ($lockedInvoice->sales_order_id) {
                $curReserved = (string) $creditAccount->reserved_exposure;
                $newReserved = bccomp($curReserved, $invoiceTotal, 2) >= 0
                    ? bcsub($curReserved, $invoiceTotal, 2)
                    : '0.00';
                $creditAccount->reserved_exposure = $newReserved;
            }

            $creditAccount->invoiced_exposure = bcadd((string) $creditAccount->invoiced_exposure, $invoiceTotal, 2);
            $creditAccount->save();

            // 4. Update Status Invoice
            $lockedInvoice->status = CustomerInvoiceStatusEnum::POSTED;
            $lockedInvoice->balance_due = $invoiceTotal;
            $lockedInvoice->posted_by_user_id = $actor->id;
            $lockedInvoice->posted_at = Carbon::now();
            $lockedInvoice->save();

            // 5. Update Status Invoicing pada Sales Order jika terkait
            if ($lockedInvoice->sales_order_id) {
                $so = SalesOrder::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('id', $lockedInvoice->sales_order_id)
                    ->lockForUpdate()
                    ->first();
                if ($so) {
                    $so->invoicing_status = SalesOrderInvoicingStatusEnum::FULLY_INVOICED;
                    $so->save();
                }
            }

            // 6. Tulis Subledger Piutang Usaha (ArLedgerEntry - Append Only)
            $runningBalance = $creditAccount->getTotalExposure();
            ArLedgerEntry::create([
                'tenant_id' => $tenantId,
                'store_id' => $lockedInvoice->store_id,
                'customer_id' => $lockedInvoice->customer_id,
                'customer_invoice_id' => $lockedInvoice->id,
                'entry_date' => Carbon::now(),
                'entry_type' => ArLedgerEntryTypeEnum::INVOICE_DEBIT,
                'reference_type' => CustomerInvoice::class,
                'reference_id' => $lockedInvoice->id,
                'reference_number' => $lockedInvoice->invoice_number,
                'debit_amount' => $invoiceTotal,
                'credit_amount' => '0.00',
                'running_balance_snapshot' => $runningBalance,
                'idempotency_key' => "ar-entry:inv:{$lockedInvoice->id}:debit",
                'actor_user_id' => $actor->id,
                'notes' => "Posting faktur penjualan {$lockedInvoice->invoice_number}",
            ]);

            // 7. Tulis Integration Outbox Menuju GL / Accounting (M7)
            AccountingReceivableOutbox::create([
                'event_id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'store_id' => $lockedInvoice->store_id,
                'event_type' => 'INVOICE_POSTED',
                'idempotency_key' => "ar-outbox:inv:{$lockedInvoice->id}:posted",
                'source_document_type' => 'CUSTOMER_INVOICE',
                'source_document_id' => $lockedInvoice->id,
                'source_document_number' => $lockedInvoice->invoice_number,
                'status' => 'pending',
                'payload' => [
                    'invoice_id' => $lockedInvoice->id,
                    'invoice_number' => $lockedInvoice->invoice_number,
                    'customer_id' => $lockedInvoice->customer_id,
                    'store_id' => $lockedInvoice->store_id,
                    'invoice_date' => $lockedInvoice->invoice_date->toDateString(),
                    'due_date' => $lockedInvoice->due_date->toDateString(),
                    'subtotal' => $lockedInvoice->subtotal,
                    'tax_amount' => $lockedInvoice->tax_amount,
                    'discount_amount' => $lockedInvoice->discount_amount,
                    'total_amount' => $lockedInvoice->total_amount,
                    'posted_by' => $actor->id,
                    'posted_at' => $lockedInvoice->posted_at->toIso8601String(),
                ],
            ]);

            // 8. Audit Trail
            $this->auditTrailService->log(
                tenantId: $tenantId,
                action: 'ar.invoice_posted',
                userId: $actor->id,
                storeId: $lockedInvoice->store_id,
                subjectType: CustomerInvoice::class,
                subjectId: $lockedInvoice->id,
                description: "Faktur penjualan {$lockedInvoice->invoice_number} berhasil diposting senilai Rp ".number_format((float) $invoiceTotal, 2, ',', '.')
            );

            return $lockedInvoice->fresh(['lines', 'customer']);
        });
    }

    /**
     * Catat dan Alokasikan Pembayaran ke Satu atau Beberapa Faktur Pelanggan (Payment Allocation).
     *
     * @param  array{amount: float|string, payment_method?: string, payment_reference?: string, store_id?: int, notes?: string}  $paymentData
     * @param  array<int, array{invoice_id: int, amount: float|string}>  $invoiceAllocations
     * @return array{total_allocated: string, unapplied_credit: string, allocations: array<int, ArPaymentAllocation>}
     */
    public function recordAndAllocatePayment(
        int $customerId,
        array $paymentData,
        array $invoiceAllocations,
        User $actor,
        ?string $idempotencyKey = null
    ): array {
        return DB::transaction(function () use ($customerId, $paymentData, $invoiceAllocations, $actor, $idempotencyKey) {
            $tenantId = (int) ($actor->tenant_id ?: $this->tenantContext->requireTenantId());

            $totalPayment = bcadd((string) ($paymentData['amount'] ?? '0.00'), '0', 2);
            if (bccomp($totalPayment, '0.00', 2) <= 0) {
                throw new DomainException('Jumlah pembayaran yang diterima harus lebih besar dari 0.');
            }

            $storeId = (int) ($paymentData['store_id'] ?? $this->tenantContext->getStoreId() ?? 1);
            $paymentRef = trim((string) ($paymentData['payment_reference'] ?? ''));
            $cashBankAccountId = ! empty($paymentData['cash_bank_account_id']) ? (int) $paymentData['cash_bank_account_id'] : null;
            $paymentMethod = (string) ($paymentData['payment_method'] ?? 'bank_transfer');
            $receiptDate = $paymentData['payment_date'] ?? $paymentData['receipt_date'] ?? now()->toDateString();
            $receiptNumber = ! empty($paymentData['receipt_number'])
                ? (string) $paymentData['receipt_number']
                : ($paymentRef ?: $this->sequenceService->nextNumber($tenantId, 'REC', $storeId));

            // Buat entitas ArReceipt
            $receipt = ArReceipt::create([
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'customer_id' => $customerId,
                'cash_bank_account_id' => $cashBankAccountId,
                'receipt_number' => $receiptNumber,
                'receipt_date' => $receiptDate,
                'total_received_amount' => $totalPayment,
                'total_allocated_amount' => '0.00',
                'unapplied_credit_amount' => '0.00',
                'bank_fee_amount' => (string) ($paymentData['bank_fee_amount'] ?? '0.00'),
                'payment_method' => $paymentMethod,
                'payment_reference' => $paymentRef ?: null,
                'status' => 'POSTED',
                'idempotency_key' => $idempotencyKey ? "ar-receipt:{$idempotencyKey}" : 'ar-receipt:'.Str::uuid(),
                'notes' => $paymentData['notes'] ?? null,
                'created_by_user_id' => $actor->id,
                'posted_by_user_id' => $actor->id,
            ]);

            // 1. Kunci Customer Credit Account (Lock Order Step 2 A0/A8)
            $creditAccount = CustomerCreditAccount::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('customer_id', $customerId)
                ->lockForUpdate()
                ->firstOrFail();

            // 2. Kunci seluruh target invoice secara terurut (ORDER BY id ASC) untuk mencegah deadlock
            $invoiceIds = array_unique(array_map(fn ($item) => (int) $item['invoice_id'], $invoiceAllocations));
            sort($invoiceIds);

            /** @var Collection<int, CustomerInvoice> $invoices */
            $invoices = CustomerInvoice::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('customer_id', $customerId)
                ->whereIn('id', $invoiceIds)
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $allocationsMade = [];
            $totalAllocated = '0.00';

            foreach ($invoiceAllocations as $alloc) {
                $invId = (int) $alloc['invoice_id'];
                $allocAmount = bcadd((string) $alloc['amount'], '0', 2);

                if (bccomp($allocAmount, '0.00', 2) <= 0) {
                    continue;
                }

                if (! $invoices->has($invId)) {
                    throw new DomainException("Faktur ID {$invId} tidak ditemukan atau bukan milik pelanggan ini.");
                }

                $invoice = $invoices->get($invId);

                if (! in_array($invoice->status, [CustomerInvoiceStatusEnum::POSTED, CustomerInvoiceStatusEnum::PARTIALLY_PAID], true)) {
                    throw new DomainException("Faktur {$invoice->invoice_number} tidak dapat menerima alokasi pembayaran. Status saat ini: {$invoice->status->value}");
                }

                // Anti-Over-Allocation: Alokasi tidak boleh melebihi balance_due
                if (bccomp($allocAmount, (string) $invoice->balance_due, 2) > 0) {
                    throw new DomainException('Alokasi pembayaran Rp '.number_format((float) $allocAmount, 2, ',', '.')." melebihi sisa tagihan faktur {$invoice->invoice_number} (Rp ".number_format((float) $invoice->balance_due, 2, ',', '.').').');
                }

                // Update invoice balances
                $newPaid = bcadd((string) $invoice->paid_amount, $allocAmount, 2);
                $newBalance = bcsub((string) $invoice->balance_due, $allocAmount, 2);

                $invoice->paid_amount = $newPaid;
                $invoice->balance_due = $newBalance;
                $invoice->status = bccomp($newBalance, '0.00', 2) === 0
                    ? CustomerInvoiceStatusEnum::PAID
                    : CustomerInvoiceStatusEnum::PARTIALLY_PAID;
                $invoice->save();

                // Kurangi invoiced_exposure pada akun kredit pelanggan
                $curInvoiced = (string) $creditAccount->invoiced_exposure;
                $creditAccount->invoiced_exposure = bccomp($curInvoiced, $allocAmount, 2) >= 0
                    ? bcsub($curInvoiced, $allocAmount, 2)
                    : '0.00';

                $allocKey = $idempotencyKey ? "{$idempotencyKey}:alloc:{$invId}" : 'alloc:'.Str::uuid();

                // Buat ArPaymentAllocation
                $allocationRecord = ArPaymentAllocation::create([
                    'tenant_id' => $tenantId,
                    'customer_id' => $customerId,
                    'customer_invoice_id' => $invoice->id,
                    'ar_receipt_id' => $receipt->id,
                    'cash_bank_account_id' => $cashBankAccountId,
                    'payment_reference' => $paymentRef ?: null,
                    'allocated_amount' => $allocAmount,
                    'allocated_at' => Carbon::now(),
                    'allocated_by_user_id' => $actor->id,
                    'is_reversed' => false,
                    'idempotency_key' => $allocKey,
                ]);

                // Buat ArLedgerEntry (PAYMENT_CREDIT)
                $runningBalance = $creditAccount->getTotalExposure();
                ArLedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'store_id' => $invoice->store_id,
                    'customer_id' => $customerId,
                    'customer_invoice_id' => $invoice->id,
                    'entry_date' => Carbon::now(),
                    'entry_type' => ArLedgerEntryTypeEnum::PAYMENT_CREDIT,
                    'reference_type' => ArPaymentAllocation::class,
                    'reference_id' => $allocationRecord->id,
                    'reference_number' => $paymentRef ?: $invoice->invoice_number,
                    'debit_amount' => '0.00',
                    'credit_amount' => $allocAmount,
                    'running_balance_snapshot' => $runningBalance,
                    'idempotency_key' => "ar-entry:alloc:{$allocationRecord->id}:credit",
                    'actor_user_id' => $actor->id,
                    'notes' => "Pelunasan faktur {$invoice->invoice_number}".($paymentRef ? " via {$paymentRef}" : ''),
                ]);

                $totalAllocated = bcadd($totalAllocated, $allocAmount, 2);
                $allocationsMade[] = $allocationRecord;
            }

            // 3. Kebijakan Overpayment (Kelebihan Bayar):
            // Sesuai Keputusan Bagian 21 No. 3: sisa lebih bayar dicatat sebagai unapplied_credit_balance
            $unappliedCredit = '0.00';
            if (bccomp($totalPayment, $totalAllocated, 2) > 0) {
                $unappliedCredit = bcsub($totalPayment, $totalAllocated, 2);
                $creditAccount->unapplied_credit_balance = bcadd(
                    (string) $creditAccount->unapplied_credit_balance,
                    $unappliedCredit,
                    2
                );

                // Catat mutasi ArLedgerEntry untuk unapplied credit
                ArLedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'store_id' => $storeId,
                    'customer_id' => $customerId,
                    'customer_invoice_id' => null,
                    'entry_date' => Carbon::now(),
                    'entry_type' => ArLedgerEntryTypeEnum::PAYMENT_CREDIT,
                    'reference_type' => CustomerCreditAccount::class,
                    'reference_id' => $creditAccount->id,
                    'reference_number' => $paymentRef ?: 'OVERPAYMENT',
                    'debit_amount' => '0.00',
                    'credit_amount' => $unappliedCredit,
                    'running_balance_snapshot' => $creditAccount->getTotalExposure(),
                    'idempotency_key' => 'ar-entry:unapplied:'.Str::uuid(),
                    'actor_user_id' => $actor->id,
                    'notes' => "Kelebihan bayar (unapplied credit) dari pembayaran {$paymentRef}",
                ]);
            }

            $creditAccount->save();

            // Perbarui total alokasi dan sisa kelebihan bayar pada ArReceipt
            $receipt->total_allocated_amount = $totalAllocated;
            $receipt->unapplied_credit_amount = $unappliedCredit;
            $receipt->save();

            // Tulis Outbox AR untuk Pelunasan & Penerimaan Pembayaran (PR-AR-03)
            AccountingReceivableOutbox::create([
                'event_id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'event_type' => 'PAYMENT_ALLOCATED',
                'idempotency_key' => "ar-outbox:receipt:{$receipt->id}",
                'source_document_type' => 'AR_RECEIPT',
                'source_document_id' => $receipt->id,
                'source_document_number' => $receipt->receipt_number,
                'status' => 'pending',
                'payload' => [
                    'receipt_id' => $receipt->id,
                    'receipt_number' => $receipt->receipt_number,
                    'customer_id' => $customerId,
                    'cash_bank_account_id' => $cashBankAccountId,
                    'total_received_amount' => $totalPayment,
                    'allocated_amount' => $totalAllocated,
                    'unapplied_credit_amount' => $unappliedCredit,
                    'bank_charge_amount' => (string) ($paymentData['bank_fee_amount'] ?? '0.00'),
                    'payment_reference' => $paymentRef,
                    'payment_method' => $paymentMethod,
                    'receipt_date' => $receiptDate,
                    'allocated_by' => $actor->id,
                    'allocated_at' => now()->toIso8601String(),
                ],
            ]);

            // 4. Audit Trail
            $this->auditTrailService->log(
                tenantId: $tenantId,
                action: 'ar.payment_allocated',
                userId: $actor->id,
                storeId: $storeId,
                subjectType: CustomerCreditAccount::class,
                subjectId: $creditAccount->id,
                description: 'Pembayaran diterima sebesar Rp '.number_format((float) $totalPayment, 2, ',', '.').', dialokasikan ke '.count($allocationsMade).' faktur (Total dialokasikan: Rp '.number_format((float) $totalAllocated, 2, ',', '.').', Sisa kredit: Rp '.number_format((float) $unappliedCredit, 2, ',', '.').')'
            );

            return [
                'total_allocated' => $totalAllocated,
                'unapplied_credit' => $unappliedCredit,
                'allocations' => $allocationsMade,
                'receipt' => $receipt,
            ];
        });
    }

    /**
     * Membalik (Reverse) Alokasi Pembayaran jika terjadi cek kosong / penolakan transfer bank.
     */
    public function reversePaymentAllocation(
        ArPaymentAllocation $allocation,
        User $actor,
        string $reason
    ): ArPaymentAllocation {
        return DB::transaction(function () use ($allocation, $actor, $reason) {
            $tenantId = (int) $allocation->tenant_id;

            $lockedAlloc = ArPaymentAllocation::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('id', $allocation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedAlloc->is_reversed) {
                throw new DomainException('Alokasi pembayaran ini sudah pernah dibatalkan sebelumnya.');
            }

            // 1. Kunci Customer Credit Account (Lock Order Step 2 A0/A8)
            $creditAccount = CustomerCreditAccount::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('customer_id', $lockedAlloc->customer_id)
                ->lockForUpdate()
                ->firstOrFail();

            // 2. Kunci Customer Invoice terkait
            $invoice = CustomerInvoice::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('id', $lockedAlloc->customer_invoice_id)
                ->lockForUpdate()
                ->firstOrFail();

            $amount = (string) $lockedAlloc->allocated_amount;

            // 3. Kembalikan balance invoice
            $invoice->paid_amount = bcsub((string) $invoice->paid_amount, $amount, 2);
            $invoice->balance_due = bcadd((string) $invoice->balance_due, $amount, 2);
            $invoice->status = bccomp((string) $invoice->paid_amount, '0.00', 2) > 0
                ? CustomerInvoiceStatusEnum::PARTIALLY_PAID
                : CustomerInvoiceStatusEnum::POSTED;
            $invoice->save();

            // 4. Kembalikan eksposur invoiced pada Customer Credit Account
            $creditAccount->invoiced_exposure = bcadd((string) $creditAccount->invoiced_exposure, $amount, 2);
            $creditAccount->save();

            // 5. Tandai alokasi sebagai reversed
            $lockedAlloc->is_reversed = true;
            $lockedAlloc->reversed_at = Carbon::now();
            $lockedAlloc->reversed_by_user_id = $actor->id;
            $lockedAlloc->reversal_reason = $reason;
            $lockedAlloc->save();

            // 6. Buat ArLedgerEntry pembalik (REVERSAL / DEBIT)
            $runningBalance = $creditAccount->getTotalExposure();
            ArLedgerEntry::create([
                'tenant_id' => $tenantId,
                'store_id' => $invoice->store_id,
                'customer_id' => $lockedAlloc->customer_id,
                'customer_invoice_id' => $invoice->id,
                'entry_date' => Carbon::now(),
                'entry_type' => ArLedgerEntryTypeEnum::REVERSAL,
                'reference_type' => ArPaymentAllocation::class,
                'reference_id' => $lockedAlloc->id,
                'reference_number' => $invoice->invoice_number,
                'debit_amount' => $amount,
                'credit_amount' => '0.00',
                'running_balance_snapshot' => $runningBalance,
                'idempotency_key' => "ar-entry:rev:{$lockedAlloc->id}:debit",
                'actor_user_id' => $actor->id,
                'notes' => "Pembatalan alokasi pembayaran: {$reason}",
            ]);

            // 7. Tulis Outbox AR
            AccountingReceivableOutbox::create([
                'event_id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'store_id' => $invoice->store_id,
                'event_type' => 'ALLOCATION_REVERSED',
                'idempotency_key' => "ar-outbox:rev:{$lockedAlloc->id}",
                'source_document_type' => 'AR_PAYMENT_ALLOCATION',
                'source_document_id' => $lockedAlloc->id,
                'source_document_number' => $invoice->invoice_number,
                'status' => 'pending',
                'payload' => [
                    'allocation_id' => $lockedAlloc->id,
                    'invoice_id' => $invoice->id,
                    'amount' => $amount,
                    'reason' => $reason,
                    'reversed_by' => $actor->id,
                    'reversed_at' => $lockedAlloc->reversed_at->toIso8601String(),
                ],
            ]);

            // 8. Audit Trail
            $this->auditTrailService->log(
                tenantId: $tenantId,
                action: 'ar.allocation_reversed',
                userId: $actor->id,
                storeId: $invoice->store_id,
                subjectType: ArPaymentAllocation::class,
                subjectId: $lockedAlloc->id,
                description: 'Alokasi pembayaran senilai Rp '.number_format((float) $amount, 2, ',', '.')." pada faktur {$invoice->invoice_number} dibatalkan. Alasan: {$reason}"
            );

            return $lockedAlloc;
        });
    }

    /**
     * Batalkan Faktur Penjualan (VOID):
     * Hanya diizinkan jika faktur berstatus POSTED dan belum ada pembayaran atau credit note yang diaplikasikan.
     */
    public function voidInvoice(CustomerInvoice $invoice, User $actor, string $reason): CustomerInvoice
    {
        return DB::transaction(function () use ($invoice, $actor, $reason) {
            $tenantId = (int) $invoice->tenant_id;

            $lockedInvoice = CustomerInvoice::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('id', $invoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedInvoice->canBeVoided()) {
                throw new DomainException("Faktur {$lockedInvoice->invoice_number} tidak dapat dibatalkan (VOID) karena sudah memiliki pembayaran atau berstatus {$lockedInvoice->status->value}.");
            }

            // 1. Kunci Customer Credit Account (Lock Order Step 2 A0/A8)
            $creditAccount = CustomerCreditAccount::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('customer_id', $lockedInvoice->customer_id)
                ->lockForUpdate()
                ->firstOrFail();

            $amount = (string) $lockedInvoice->total_amount;

            // 2. Kurangi eksposur invoiced
            $curInvoiced = (string) $creditAccount->invoiced_exposure;
            $creditAccount->invoiced_exposure = bccomp($curInvoiced, $amount, 2) >= 0
                ? bcsub($curInvoiced, $amount, 2)
                : '0.00';

            // Jika berasal dari Sales Order, kembalikan reserved_exposure
            if ($lockedInvoice->sales_order_id) {
                $creditAccount->reserved_exposure = bcadd((string) $creditAccount->reserved_exposure, $amount, 2);

                $so = SalesOrder::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('id', $lockedInvoice->sales_order_id)
                    ->lockForUpdate()
                    ->first();
                if ($so) {
                    $so->invoicing_status = SalesOrderInvoicingStatusEnum::UNINVOICED;
                    $so->save();
                }
            }

            $creditAccount->save();

            // 3. Update Invoice Status
            $lockedInvoice->status = CustomerInvoiceStatusEnum::VOIDED;
            $lockedInvoice->balance_due = '0.00';
            $lockedInvoice->voided_by_user_id = $actor->id;
            $lockedInvoice->voided_at = Carbon::now();
            $lockedInvoice->void_reason = $reason;
            $lockedInvoice->save();

            // 4. Buat ArLedgerEntry pembalik (REVERSAL / CREDIT)
            $runningBalance = $creditAccount->getTotalExposure();
            ArLedgerEntry::create([
                'tenant_id' => $tenantId,
                'store_id' => $lockedInvoice->store_id,
                'customer_id' => $lockedInvoice->customer_id,
                'customer_invoice_id' => $lockedInvoice->id,
                'entry_date' => Carbon::now(),
                'entry_type' => ArLedgerEntryTypeEnum::REVERSAL,
                'reference_type' => CustomerInvoice::class,
                'reference_id' => $lockedInvoice->id,
                'reference_number' => $lockedInvoice->invoice_number,
                'debit_amount' => '0.00',
                'credit_amount' => $amount,
                'running_balance_snapshot' => $runningBalance,
                'idempotency_key' => "ar-entry:void:{$lockedInvoice->id}:credit",
                'actor_user_id' => $actor->id,
                'notes' => "Pembatalan faktur (VOID): {$reason}",
            ]);

            // 5. Tulis Outbox AR
            AccountingReceivableOutbox::create([
                'event_id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'store_id' => $lockedInvoice->store_id,
                'event_type' => 'INVOICE_VOIDED',
                'idempotency_key' => "ar-outbox:void:{$lockedInvoice->id}",
                'source_document_type' => 'CUSTOMER_INVOICE',
                'source_document_id' => $lockedInvoice->id,
                'source_document_number' => $lockedInvoice->invoice_number,
                'status' => 'pending',
                'payload' => [
                    'invoice_id' => $lockedInvoice->id,
                    'invoice_number' => $lockedInvoice->invoice_number,
                    'amount' => $amount,
                    'reason' => $reason,
                    'voided_by' => $actor->id,
                    'voided_at' => $lockedInvoice->voided_at->toIso8601String(),
                ],
            ]);

            // 6. Audit Trail
            $this->auditTrailService->log(
                tenantId: $tenantId,
                action: 'ar.invoice_voided',
                userId: $actor->id,
                storeId: $lockedInvoice->store_id,
                subjectType: CustomerInvoice::class,
                subjectId: $lockedInvoice->id,
                description: "Faktur penjualan {$lockedInvoice->invoice_number} berhasil dibatalkan (VOID). Alasan: {$reason}"
            );

            return $lockedInvoice;
        });
    }

    /**
     * Hitung Laporan Aging Piutang (AR Aging Report) per Pelanggan atau Konsolidasi Cabang.
     *
     * @return array{current: string, 1_30: string, 31_60: string, 61_90: string, over_90: string, total: string}
     */
    public function getAgingReport(int $tenantId, ?int $storeId = null, ?int $customerId = null): array
    {
        $today = Carbon::today();

        $query = CustomerInvoice::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', [CustomerInvoiceStatusEnum::POSTED, CustomerInvoiceStatusEnum::PARTIALLY_PAID])
            ->where('balance_due', '>', 0);

        if ($storeId !== null && $storeId > 0) {
            $query->where('store_id', $storeId);
        }

        if ($customerId !== null && $customerId > 0) {
            $query->where('customer_id', $customerId);
        }

        $invoices = $query->get(['due_date', 'balance_due']);

        $buckets = [
            'current' => '0.00',
            '1_30' => '0.00',
            '31_60' => '0.00',
            '61_90' => '0.00',
            'over_90' => '0.00',
            'total' => '0.00',
        ];

        foreach ($invoices as $inv) {
            $due = Carbon::parse($inv->due_date);
            $amount = (string) $inv->balance_due;

            $buckets['total'] = bcadd($buckets['total'], $amount, 2);

            if ($due->gte($today)) {
                $buckets['current'] = bcadd($buckets['current'], $amount, 2);
            } else {
                $days = $due->diffInDays($today);
                if ($days <= 30) {
                    $buckets['1_30'] = bcadd($buckets['1_30'], $amount, 2);
                } elseif ($days <= 60) {
                    $buckets['31_60'] = bcadd($buckets['31_60'], $amount, 2);
                } elseif ($days <= 90) {
                    $buckets['61_90'] = bcadd($buckets['61_90'], $amount, 2);
                } else {
                    $buckets['over_90'] = bcadd($buckets['over_90'], $amount, 2);
                }
            }
        }

        return $buckets;
    }

    /**
     * Hasilkan Rekening Koran / Statement Mutasi Piutang Pelanggan (Customer Statement).
     *
     * @return array{opening_balance: string, closing_balance: string, transactions: array<int, array<string, mixed>>}
     */
    public function generateCustomerStatement(int $customerId, Carbon $startDate, Carbon $endDate): array
    {
        $tenantId = (int) $this->tenantContext->requireTenantId();

        // 1. Hitung Saldo Awal (Opening Balance): sum(debit - credit) sebelum startDate
        $beforeEntries = ArLedgerEntry::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->where('entry_date', '<', $startDate->startOfDay())
            ->get(['debit_amount', 'credit_amount']);

        $openingBalance = '0.00';
        foreach ($beforeEntries as $e) {
            $diff = bcsub((string) $e->debit_amount, (string) $e->credit_amount, 2);
            $openingBalance = bcadd($openingBalance, $diff, 2);
        }

        // 2. Ambil seluruh mutasi dalam rentang tanggal
        $periodEntries = ArLedgerEntry::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->whereBetween('entry_date', [$startDate->startOfDay(), $endDate->endOfDay()])
            ->orderBy('entry_date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $runningBalance = $openingBalance;
        $transactions = [];

        foreach ($periodEntries as $entry) {
            $debit = (string) $entry->debit_amount;
            $credit = (string) $entry->credit_amount;
            $diff = bcsub($debit, $credit, 2);
            $runningBalance = bcadd($runningBalance, $diff, 2);

            $transactions[] = [
                'id' => $entry->id,
                'entry_date' => $entry->entry_date->toIso8601String(),
                'entry_type' => $entry->entry_type->value,
                'entry_type_label' => $entry->entry_type->label(),
                'reference_number' => $entry->reference_number,
                'debit_amount' => $debit,
                'credit_amount' => $credit,
                'running_balance' => $runningBalance,
                'notes' => $entry->notes,
            ];
        }

        return [
            'opening_balance' => $openingBalance,
            'closing_balance' => $runningBalance,
            'transactions' => $transactions,
        ];
    }

    /**
     * Helper: Buat Faktur Penjualan dari Sales Order.
     *
     * @param  array<string, mixed>  $options
     */
    protected function createInvoiceFromSalesOrder(
        SalesOrder $salesOrder,
        array $options,
        User $actor,
        ?string $idempotencyKey
    ): CustomerInvoice {
        $tenantId = (int) $salesOrder->tenant_id;
        $salesOrder->loadMissing(['lines.product', 'customer.creditAccount']);

        $invoiceDate = ! empty($options['invoice_date'])
            ? Carbon::parse($options['invoice_date'])->toDateString()
            : Carbon::today()->toDateString();

        $termsDays = (int) ($options['payment_terms_days'] ?? $salesOrder->payment_terms_days ?? 0);
        $dueDate = Carbon::parse($invoiceDate)->addDays($termsDays)->toDateString();

        $storeId = (int) $salesOrder->store_id;
        $invNumber = $this->sequenceService->nextNumber($tenantId, 'INV', $storeId);

        $customer = $salesOrder->customer;
        $snapshot = [
            'customer_id' => $customer->id,
            'customer_code' => $customer->customer_code,
            'name' => $customer->name,
            'company_name' => $customer->company_name,
            'tax_id' => $customer->tax_id,
            'phone' => $customer->phone,
            'email' => $customer->email,
        ];

        $invoice = CustomerInvoice::create([
            'tenant_id' => $tenantId,
            'store_id' => $storeId,
            'invoice_number' => $invNumber,
            'customer_id' => $salesOrder->customer_id,
            'sales_order_id' => $salesOrder->id,
            'order_id' => null,
            'customer_po_number' => $salesOrder->customer_po_number,
            'status' => CustomerInvoiceStatusEnum::DRAFT,
            'invoice_date' => $invoiceDate,
            'due_date' => $dueDate,
            'payment_terms_days' => $termsDays,
            'currency_code' => 'IDR',
            'subtotal' => (string) $salesOrder->subtotal,
            'discount_amount' => (string) $salesOrder->discount_amount,
            'tax_amount' => (string) $salesOrder->tax_amount,
            'shipping_amount' => (string) $salesOrder->shipping_amount,
            'total_amount' => (string) $salesOrder->total_amount,
            'paid_amount' => '0.00',
            'credit_applied_amount' => '0.00',
            'balance_due' => (string) $salesOrder->total_amount,
            'customer_snapshot_json' => $snapshot,
            'notes' => $options['notes'] ?? null,
            'terms_and_conditions' => $options['terms_and_conditions'] ?? null,
            'idempotency_key' => $idempotencyKey,
        ]);

        $lineNum = 1;
        foreach ($salesOrder->lines as $soLine) {
            CustomerInvoiceLine::create([
                'tenant_id' => $tenantId,
                'customer_invoice_id' => $invoice->id,
                'sales_order_line_id' => $soLine->id,
                'order_item_id' => null,
                'line_number' => $lineNum++,
                'product_id' => $soLine->product_id,
                'variant_id' => $soLine->variant_id,
                'uom_id' => $soLine->uom_id,
                'description' => $soLine->product?->product_name ?: "Produk #{$soLine->product_id}",
                'quantity' => (string) $soLine->ordered_qty,
                'unit_price' => (string) $soLine->unit_price,
                'discount_amount' => (string) $soLine->discount_amount,
                'tax_rate' => (string) $soLine->tax_rate,
                'tax_amount' => (string) $soLine->tax_amount,
                'line_total' => (string) $soLine->line_total,
            ]);
        }

        if (! empty($options['post_immediately'])) {
            return $this->postInvoice($invoice, $actor);
        }

        return $invoice->load('lines');
    }

    /**
     * Helper: Buat Faktur Penjualan Manual Langsung (Direct Invoice).
     *
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $options
     */
    protected function createDirectInvoice(
        array $source,
        array $options,
        User $actor,
        ?string $idempotencyKey
    ): CustomerInvoice {
        $tenantId = (int) ($actor->tenant_id ?: $this->tenantContext->requireTenantId());

        $customerId = (int) ($source['customer_id'] ?? 0);
        $customer = Customer::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('id', $customerId)
            ->first();

        if (! $customer) {
            throw ValidationException::withMessages([
                'customer_id' => ['Pelanggan tidak valid pada tenant ini.'],
            ]);
        }

        $storeId = (int) ($source['store_id'] ?? $customer->default_store_id ?: $this->tenantContext->getStoreId() ?: 1);
        $invNumber = ! empty($source['invoice_number'])
            ? trim((string) $source['invoice_number'])
            : $this->sequenceService->nextNumber($tenantId, 'INV', $storeId);

        $invoiceDate = ! empty($source['invoice_date'])
            ? Carbon::parse($source['invoice_date'])->toDateString()
            : Carbon::today()->toDateString();

        $creditAccount = $customer->creditAccount;
        $termsDays = (int) ($source['payment_terms_days'] ?? $creditAccount?->payment_terms_days ?? 0);
        $dueDate = Carbon::parse($invoiceDate)->addDays($termsDays)->toDateString();

        $items = $source['items'] ?? $source['lines'] ?? [];
        if (! is_array($items) || count($items) === 0) {
            throw ValidationException::withMessages([
                'items' => ['Faktur penjualan wajib memiliki minimal 1 item tagihan.'],
            ]);
        }

        $subtotal = '0.00';
        $discountAmount = '0.00';
        $taxAmount = '0.00';
        $totalAmount = '0.00';

        $preparedLines = [];
        $lineNum = 1;

        foreach ($items as $item) {
            $qty = bcadd((string) ($item['quantity'] ?? '1'), '0', 4);
            $price = bcadd((string) ($item['unit_price'] ?? '0'), '0', 2);
            $lineDisc = bcadd((string) ($item['discount_amount'] ?? '0'), '0', 2);
            $taxRate = bcadd((string) ($item['tax_rate'] ?? '0'), '0', 2);

            $gross = bcmul($qty, $price, 2);
            $net = bcsub($gross, $lineDisc, 2);
            $tax = bccomp($taxRate, '0.00', 2) > 0
                ? bcdiv(bcmul($net, $taxRate, 4), '100', 2)
                : '0.00';
            $lineTotal = bcadd($net, $tax, 2);

            $subtotal = bcadd($subtotal, $gross, 2);
            $discountAmount = bcadd($discountAmount, $lineDisc, 2);
            $taxAmount = bcadd($taxAmount, $tax, 2);
            $totalAmount = bcadd($totalAmount, $lineTotal, 2);

            $prod = Product::withoutGlobalScopes()->find((int) $item['product_id']);
            $desc = ! empty($item['description'])
                ? (string) $item['description']
                : ($prod?->product_name ?: "Item #{$item['product_id']}");

            $preparedLines[] = [
                'line_number' => $lineNum++,
                'product_id' => (int) $item['product_id'],
                'variant_id' => ! empty($item['variant_id']) ? (int) $item['variant_id'] : null,
                'uom_id' => ! empty($item['uom_id']) ? (int) $item['uom_id'] : null,
                'description' => $desc,
                'quantity' => $qty,
                'unit_price' => $price,
                'discount_amount' => $lineDisc,
                'tax_rate' => $taxRate,
                'tax_amount' => $tax,
                'line_total' => $lineTotal,
            ];
        }

        $shippingAmount = bcadd((string) ($source['shipping_amount'] ?? '0.00'), '0', 2);
        $totalAmount = bcadd($totalAmount, $shippingAmount, 2);

        $snapshot = [
            'customer_id' => $customer->id,
            'customer_code' => $customer->customer_code,
            'name' => $customer->name,
            'company_name' => $customer->company_name,
            'tax_id' => $customer->tax_id,
            'phone' => $customer->phone,
            'email' => $customer->email,
        ];

        $invoice = CustomerInvoice::create([
            'tenant_id' => $tenantId,
            'store_id' => $storeId,
            'invoice_number' => $invNumber,
            'customer_id' => $customerId,
            'sales_order_id' => null,
            'order_id' => null,
            'customer_po_number' => $source['customer_po_number'] ?? null,
            'status' => CustomerInvoiceStatusEnum::DRAFT,
            'invoice_date' => $invoiceDate,
            'due_date' => $dueDate,
            'payment_terms_days' => $termsDays,
            'currency_code' => 'IDR',
            'subtotal' => $subtotal,
            'discount_amount' => $discountAmount,
            'tax_amount' => $taxAmount,
            'shipping_amount' => $shippingAmount,
            'total_amount' => $totalAmount,
            'paid_amount' => '0.00',
            'credit_applied_amount' => '0.00',
            'balance_due' => $totalAmount,
            'customer_snapshot_json' => $snapshot,
            'notes' => $source['notes'] ?? null,
            'terms_and_conditions' => $source['terms_and_conditions'] ?? null,
            'idempotency_key' => $idempotencyKey,
        ]);

        foreach ($preparedLines as $l) {
            CustomerInvoiceLine::create([
                'tenant_id' => $tenantId,
                'customer_invoice_id' => $invoice->id,
                'sales_order_line_id' => null,
                'order_item_id' => null,
                'line_number' => $l['line_number'],
                'product_id' => $l['product_id'],
                'variant_id' => $l['variant_id'],
                'uom_id' => $l['uom_id'],
                'description' => $l['description'],
                'quantity' => $l['quantity'],
                'unit_price' => $l['unit_price'],
                'discount_amount' => $l['discount_amount'],
                'tax_rate' => $l['tax_rate'],
                'tax_amount' => $l['tax_amount'],
                'line_total' => $l['line_total'],
            ]);
        }

        if (! empty($options['post_immediately'])) {
            return $this->postInvoice($invoice, $actor);
        }

        return $invoice->load('lines');
    }
}
