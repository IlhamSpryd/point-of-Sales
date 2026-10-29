<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Finance\DataTransferObjects\FinancialEventDto;
use App\Modules\Finance\Enums\CashBankTransactionStatusEnum;
use App\Modules\Finance\Enums\CashBankTransactionTypeEnum;
use App\Modules\Finance\Enums\JournalStatusEnum;
use App\Modules\Finance\Models\ArReceipt;
use App\Modules\Finance\Models\CashBankAccount;
use App\Modules\Finance\Models\CashBankTransaction;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Sales\Models\AccountingReceivableOutbox;
use App\Modules\Sales\Models\ArPaymentAllocation;
use App\Modules\Sales\Models\CustomerInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ArOutboxConsumerService
{
    public function __construct(
        protected PostingEngineService $postingEngine,
        protected JournalEntryService $journalEntryService
    ) {}

    /**
     * Memproses antrean pending pada accounting_receivable_outboxes.
     */
    public function processPendingOutbox(?int $tenantId = null, int $limit = 50): int
    {
        $query = AccountingReceivableOutbox::withoutGlobalScopes()
            ->where('status', 'pending')
            ->orderBy('id', 'asc')
            ->limit($limit);

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        $records = $query->get();
        $processedCount = 0;

        foreach ($records as $outbox) {
            try {
                $this->processOutboxRecord($outbox);
                $processedCount++;
            } catch (Throwable $e) {
                Log::error("Gagal memproses accounting_receivable_outbox #{$outbox->id}: {$e->getMessage()}", [
                    'outbox_id' => $outbox->id,
                    'event_type' => $outbox->event_type,
                    'trace' => $e->getTraceAsString(),
                ]);

                $outbox->retry_count = ($outbox->retry_count ?? 0) + 1;
                $outbox->last_error = substr($e->getMessage(), 0, 1000);
                if ($outbox->retry_count >= 5) {
                    $outbox->status = 'failed';
                }
                $outbox->save();
            }
        }

        return $processedCount;
    }

    /**
     * Memproses satu baris outbox AR secara atomik dan idempoten.
     */
    public function processOutboxRecord(AccountingReceivableOutbox $outbox): void
    {
        switch ($outbox->event_type) {
            case 'INVOICE_POSTED':
            case 'ar.invoice.posted':
                $this->processInvoicePosted($outbox);
                break;

            case 'PAYMENT_ALLOCATED':
            case 'ar.payment.received_and_allocated':
                $this->processPaymentAllocated($outbox);
                break;

            case 'CREDIT_NOTE_APPLIED':
            case 'ar.credit_note.applied':
                $this->processCreditNoteApplied($outbox);
                break;

            case 'INVOICE_VOIDED':
            case 'ar.invoice.voided':
                $this->processInvoiceVoided($outbox);
                break;

            case 'ALLOCATION_REVERSED':
            case 'ar.allocation.reversed':
                $this->processAllocationReversed($outbox);
                break;

            default:
                $outbox->status = 'processed_by_m7';
                $outbox->processed_at = now();
                $outbox->save();
                break;
        }
    }

    /**
     * Memproses INVOICE_POSTED (PR-AR-01):
     * Debit AR Control, Debit Sales Discount, Credit Sales Revenue B2B, Credit Shipping, Credit PPN Keluaran.
     */
    protected function processInvoicePosted(AccountingReceivableOutbox $outbox): void
    {
        $tenantId = (int) $outbox->tenant_id;
        $payload = is_array($outbox->payload) ? $outbox->payload : json_decode((string) $outbox->payload, true);

        DB::transaction(function () use ($outbox, $tenantId, $payload) {
            $invoiceId = (int) ($payload['invoice_id'] ?? $outbox->source_document_id);

            /** @var CustomerInvoice|null $invoice */
            $invoice = CustomerInvoice::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->find($invoiceId);

            if ($invoice && $invoice->gl_journal_entry_id) {
                $outbox->status = 'processed_by_m7';
                $outbox->processed_at = now();
                $outbox->save();

                return;
            }

            $storeId = $invoice ? (int) $invoice->store_id : ($outbox->store_id ? (int) $outbox->store_id : null);
            $subtotal = (string) ($payload['subtotal'] ?? $invoice?->subtotal ?? '0.00');
            $taxAmount = (string) ($payload['tax_amount'] ?? $invoice?->tax_amount ?? '0.00');
            $discountAmount = (string) ($payload['discount_amount'] ?? $invoice?->discount_amount ?? '0.00');
            $shippingAmount = (string) ($payload['shipping_amount'] ?? $invoice?->shipping_amount ?? '0.00');
            $totalAmount = (string) ($payload['total_amount'] ?? $invoice?->total_amount ?? '0.00');
            $customerId = (int) ($payload['customer_id'] ?? $invoice?->customer_id ?? 0);
            $invoiceDate = $payload['invoice_date'] ?? $invoice?->invoice_date?->toDateString() ?? now()->toDateString();

            $eventDto = new FinancialEventDto(
                tenantId: $tenantId,
                storeId: $storeId,
                sourceModule: 'Sales',
                sourceType: 'customer_invoice',
                sourceId: $invoiceId,
                sourceDocumentNumber: $outbox->source_document_number,
                eventCode: 'ar.invoice.posted',
                idempotencyKey: "m6:ar_invoice_posted:{$tenantId}:{$invoiceId}",
                occurredAt: $outbox->created_at ?? now(),
                accountingDate: $invoiceDate,
                payload: [
                    'subtotal_amount' => $subtotal,
                    'tax_amount' => $taxAmount,
                    'discount_amount' => $discountAmount,
                    'shipping_amount' => $shippingAmount,
                    'total_amount' => $totalAmount,
                    'customer_id' => $customerId,
                ],
                userId: ! empty($payload['posted_by']) ? (int) $payload['posted_by'] : null
            );

            $journal = $this->postingEngine->postEvent($eventDto);

            if ($invoice && ! $invoice->gl_journal_entry_id) {
                $invoice->gl_journal_entry_id = $journal->id;
                $invoice->save();
            }

            $outbox->status = 'processed_by_m7';
            $outbox->processed_at = now();
            $outbox->save();
        });
    }

    /**
     * Memproses PAYMENT_ALLOCATED (PR-AR-03):
     * Debit Kas/Bank, Debit Biaya Admin, Credit AR Control, Credit Kelebihan Bayar Pelanggan.
     */
    protected function processPaymentAllocated(AccountingReceivableOutbox $outbox): void
    {
        $tenantId = (int) $outbox->tenant_id;
        $payload = is_array($outbox->payload) ? $outbox->payload : json_decode((string) $outbox->payload, true);

        DB::transaction(function () use ($outbox, $tenantId, $payload) {
            $receiptId = ! empty($payload['receipt_id']) ? (int) $payload['receipt_id'] : null;

            /** @var ArReceipt|null $receipt */
            $receipt = null;
            if ($receiptId) {
                $receipt = ArReceipt::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->lockForUpdate()
                    ->find($receiptId);
            }

            if ($receipt && $receipt->journal_entry_id) {
                $outbox->status = 'processed_by_m7';
                $outbox->processed_at = now();
                $outbox->save();

                return;
            }

            $storeId = $receipt ? $receipt->store_id : ($outbox->store_id ? (int) $outbox->store_id : null);
            $totalReceived = (string) ($payload['total_received_amount'] ?? $receipt?->total_received_amount ?? $payload['allocated_amount'] ?? '0.00');
            $allocatedAmount = (string) ($payload['allocated_amount'] ?? $receipt?->total_allocated_amount ?? '0.00');
            $unappliedCredit = (string) ($payload['unapplied_credit_amount'] ?? $receipt?->unapplied_credit_amount ?? '0.00');
            $bankCharge = (string) ($payload['bank_charge_amount'] ?? $receipt?->bank_fee_amount ?? '0.00');
            $cashBankAccountId = ! empty($payload['cash_bank_account_id']) ? (int) $payload['cash_bank_account_id'] : ($receipt?->cash_bank_account_id);
            $customerId = (int) ($payload['customer_id'] ?? $receipt?->customer_id ?? 0);
            $receiptDate = $payload['receipt_date'] ?? $receipt?->receipt_date?->toDateString() ?? now()->toDateString();
            $sourceDocId = $receipt ? $receipt->id : (int) $outbox->source_document_id;
            $sourceDocNumber = $receipt ? $receipt->receipt_number : (string) $outbox->source_document_number;

            $eventDto = new FinancialEventDto(
                tenantId: $tenantId,
                storeId: $storeId,
                sourceModule: 'Finance',
                sourceType: 'ar_receipt',
                sourceId: $sourceDocId,
                sourceDocumentNumber: $sourceDocNumber,
                eventCode: 'ar.payment.received_and_allocated',
                idempotencyKey: "m6:ar_payment_receipt:{$tenantId}:{$sourceDocId}",
                occurredAt: $outbox->created_at ?? now(),
                accountingDate: $receiptDate,
                payload: [
                    'total_received_amount' => $totalReceived,
                    'allocated_amount' => $allocatedAmount,
                    'unapplied_credit_amount' => $unappliedCredit,
                    'bank_charge_amount' => $bankCharge,
                    'cash_bank_account_id' => $cashBankAccountId,
                    'customer_id' => $customerId,
                ],
                userId: ! empty($payload['allocated_by']) ? (int) $payload['allocated_by'] : null
            );

            $journal = $this->postingEngine->postEvent($eventDto);

            // Mutasi Kas/Bank jika akun kas/bank terpasang
            if ($cashBankAccountId) {
                /** @var CashBankAccount|null $account */
                $account = CashBankAccount::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->lockForUpdate()
                    ->find($cashBankAccountId);

                if ($account) {
                    $account->current_balance = bcadd((string) $account->current_balance, $totalReceived, 2);
                    $account->save();

                    $cbt = CashBankTransaction::create([
                        'tenant_id' => $tenantId,
                        'store_id' => $storeId,
                        'cash_bank_account_id' => $account->id,
                        'journal_entry_id' => $journal->id,
                        'transaction_type' => CashBankTransactionTypeEnum::RECEIPT,
                        'transaction_number' => 'CBT-AR-'.$journal->journal_number,
                        'transaction_date' => $receiptDate,
                        'amount' => $totalReceived,
                        'running_balance_after' => $account->current_balance,
                        'description' => "Penerimaan Pelunasan Piutang #{$sourceDocNumber}",
                        'reference_number' => $sourceDocNumber,
                        'idempotency_key' => "cbt:ar_receipt:{$tenantId}:{$sourceDocId}",
                        'status' => CashBankTransactionStatusEnum::POSTED,
                        'created_by_user_id' => ! empty($payload['allocated_by']) ? (int) $payload['allocated_by'] : null,
                        'posted_by_user_id' => ! empty($payload['allocated_by']) ? (int) $payload['allocated_by'] : null,
                    ]);

                    if ($receipt) {
                        $receipt->cash_bank_transaction_id = $cbt->id;
                    }
                }
            }

            if ($receipt) {
                $receipt->journal_entry_id = $journal->id;
                $receipt->save();

                // Tautkan journal_entry_id ke alokasi
                ArPaymentAllocation::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('ar_receipt_id', $receipt->id)
                    ->update(['journal_entry_id' => $journal->id]);
            }

            $outbox->status = 'processed_by_m7';
            $outbox->processed_at = now();
            $outbox->save();
        });
    }

    /**
     * Memproses CREDIT_NOTE_APPLIED (PR-AR-04):
     * Debit Sales Return, Debit PPN Keluaran, Credit AR Control.
     */
    protected function processCreditNoteApplied(AccountingReceivableOutbox $outbox): void
    {
        $tenantId = (int) $outbox->tenant_id;
        $payload = is_array($outbox->payload) ? $outbox->payload : json_decode((string) $outbox->payload, true);

        DB::transaction(function () use ($outbox, $tenantId, $payload) {
            $appliedAmount = (string) ($payload['applied_amount'] ?? '0.00');
            $taxAmount = (string) ($payload['tax_amount'] ?? '0.00');
            $subtotalAmount = bcsub($appliedAmount, $taxAmount, 2);
            if (bccomp($subtotalAmount, '0.00', 2) < 0) {
                $subtotalAmount = $appliedAmount;
                $taxAmount = '0.00';
            }

            $eventDto = new FinancialEventDto(
                tenantId: $tenantId,
                storeId: $outbox->store_id ? (int) $outbox->store_id : null,
                sourceModule: 'Sales',
                sourceType: 'sales_credit_note',
                sourceId: (int) ($payload['credit_note_id'] ?? $outbox->source_document_id),
                sourceDocumentNumber: $outbox->source_document_number,
                eventCode: 'ar.credit_note.applied',
                idempotencyKey: "m6:credit_note_applied:{$tenantId}:{$outbox->id}",
                occurredAt: $outbox->created_at ?? now(),
                accountingDate: now()->toDateString(),
                payload: [
                    'applied_amount' => $appliedAmount,
                    'subtotal_amount' => $subtotalAmount,
                    'tax_amount' => $taxAmount,
                    'customer_id' => $payload['customer_id'] ?? null,
                ],
                userId: ! empty($payload['applied_by_user_id']) ? (int) $payload['applied_by_user_id'] : null
            );

            $this->postingEngine->postEvent($eventDto);

            $outbox->status = 'processed_by_m7';
            $outbox->processed_at = now();
            $outbox->save();
        });
    }

    /**
     * Memproses INVOICE_VOIDED: membalik jurnal faktur GL yang bersangkutan.
     */
    protected function processInvoiceVoided(AccountingReceivableOutbox $outbox): void
    {
        $tenantId = (int) $outbox->tenant_id;
        $payload = is_array($outbox->payload) ? $outbox->payload : json_decode((string) $outbox->payload, true);

        DB::transaction(function () use ($outbox, $tenantId, $payload) {
            $invoiceId = (int) ($payload['invoice_id'] ?? $outbox->source_document_id);

            /** @var CustomerInvoice|null $invoice */
            $invoice = CustomerInvoice::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->find($invoiceId);

            if ($invoice && $invoice->gl_journal_entry_id) {
                /** @var JournalEntry|null $journal */
                $journal = JournalEntry::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->find($invoice->gl_journal_entry_id);

                if ($journal && $journal->status === JournalStatusEnum::POSTED) {
                    $this->journalEntryService->reverseJournal(
                        originalJournal: $journal,
                        reversalDate: now()->toDateString(),
                        reason: "Pembatalan Faktur Piutang #{$invoice->invoice_number}: ".($payload['reason'] ?? 'Invoice Voided')
                    );
                }
            }

            $outbox->status = 'processed_by_m7';
            $outbox->processed_at = now();
            $outbox->save();
        });
    }

    /**
     * Memproses ALLOCATION_REVERSED: membalik jurnal penerimaan atau penyesuaian GL.
     */
    protected function processAllocationReversed(AccountingReceivableOutbox $outbox): void
    {
        $outbox->status = 'processed_by_m7';
        $outbox->processed_at = now();
        $outbox->save();
    }
}
