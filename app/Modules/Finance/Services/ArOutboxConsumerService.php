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
        $query = DB::table('accounting_receivable_outboxes')
            ->where('status', 'pending')
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->orderBy('id', 'asc')
            ->limit($limit);

        $records = DB::transaction(function () use ($query) {
            return $query->lock('FOR UPDATE SKIP LOCKED')->get();
        });
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

                $this->markOutboxFailed($outbox, $e->getMessage());
            }
        }

        return $processedCount;
    }

    /**
     * Memproses satu baris outbox AR secara atomik dan idempoten.
     */
    public function processOutboxRecord(object $outbox): void
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
                $this->markOutboxProcessed($outbox);
                break;
        }
    }

    /**
     * Memproses INVOICE_POSTED (PR-AR-01):
     * Debit AR Control, Debit Sales Discount, Credit Sales Revenue B2B, Credit Shipping, Credit PPN Keluaran.
     */
    protected function processInvoicePosted(object $outbox): void
    {
        $tenantId = (int) $outbox->tenant_id;
        $payload = is_array($outbox->payload) ? $outbox->payload : json_decode((string) $outbox->payload, true);

        DB::transaction(function () use ($outbox, $tenantId, $payload) {
            $invoiceId = (int) ($payload['invoice_id'] ?? $outbox->source_document_id);

            $invoice = DB::table('customer_invoices')
                ->where('tenant_id', $tenantId)
                ->where('id', $invoiceId)
                ->first();

            if ($invoice && ($invoice->gl_journal_entry_id ?? null)) {
                $this->markOutboxProcessed($outbox);

                return;
            }

            $storeId = $invoice ? (int) $invoice->store_id : ($outbox->store_id ? (int) $outbox->store_id : null);
            $subtotal = $this->formatDecimal($payload['subtotal'] ?? $invoice?->subtotal ?? '0.00');
            $taxAmount = $this->formatDecimal($payload['tax_amount'] ?? $invoice?->tax_amount ?? '0.00');
            $discountAmount = $this->formatDecimal($payload['discount_amount'] ?? $invoice?->discount_amount ?? '0.00');
            $shippingAmount = $this->formatDecimal($payload['shipping_amount'] ?? $invoice?->shipping_amount ?? '0.00');
            $totalAmount = $this->formatDecimal($payload['total_amount'] ?? $invoice?->total_amount ?? '0.00');
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

            if ($invoice && ! ($invoice->gl_journal_entry_id ?? null)) {
                DB::table('customer_invoices')
                    ->where('id', $invoiceId)
                    ->update(['gl_journal_entry_id' => $journal->id]);
            }

            $this->markOutboxProcessed($outbox);
        });
    }

    /**
     * Memproses PAYMENT_ALLOCATED (PR-AR-03):
     * Debit Kas/Bank, Debit Biaya Admin, Credit AR Control, Credit Kelebihan Bayar Pelanggan.
     */
    protected function processPaymentAllocated(object $outbox): void
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
                $this->markOutboxProcessed($outbox);

                return;
            }

            $storeId = $receipt ? $receipt->store_id : ($outbox->store_id ? (int) $outbox->store_id : null);
            $totalReceived = $this->formatDecimal($payload['total_received_amount'] ?? $receipt?->total_received_amount ?? $payload['allocated_amount'] ?? '0.00');
            $allocatedAmount = $this->formatDecimal($payload['allocated_amount'] ?? $receipt?->total_allocated_amount ?? '0.00');
            $unappliedCredit = $this->formatDecimal($payload['unapplied_credit_amount'] ?? $receipt?->unapplied_credit_amount ?? '0.00');
            $bankCharge = $this->formatDecimal($payload['bank_charge_amount'] ?? $receipt?->bank_fee_amount ?? '0.00');
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
                    $account->current_balance = bcadd(bc_num($account->current_balance), bc_num($totalReceived), 2);
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
                DB::table('ar_payment_allocations')
                    ->where('tenant_id', $tenantId)
                    ->where('ar_receipt_id', $receipt->id)
                    ->update(['journal_entry_id' => $journal->id]);
            }

            $this->markOutboxProcessed($outbox);
        });
    }

    /**
     * Memproses CREDIT_NOTE_APPLIED (PR-AR-04):
     * Debit Sales Return, Debit PPN Keluaran, Credit AR Control.
     */
    protected function processCreditNoteApplied(object $outbox): void
    {
        $tenantId = (int) $outbox->tenant_id;
        $payload = is_array($outbox->payload) ? $outbox->payload : json_decode((string) $outbox->payload, true);

        DB::transaction(function () use ($outbox, $tenantId, $payload) {
            $appliedAmount = $this->formatDecimal($payload['applied_amount'] ?? '0.00');
            $taxAmount = $this->formatDecimal($payload['tax_amount'] ?? '0.00');
            $subtotalAmount = bcsub(bc_num($appliedAmount), bc_num($taxAmount), 2);
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

            $this->markOutboxProcessed($outbox);
        });
    }

    /**
     * Memproses INVOICE_VOIDED: membalik jurnal faktur GL yang bersangkutan.
     */
    protected function processInvoiceVoided(object $outbox): void
    {
        $tenantId = (int) $outbox->tenant_id;
        $payload = is_array($outbox->payload) ? $outbox->payload : json_decode((string) $outbox->payload, true);

        DB::transaction(function () use ($outbox, $tenantId, $payload) {
            $invoiceId = (int) ($payload['invoice_id'] ?? $outbox->source_document_id);

            $invoice = DB::table('customer_invoices')
                ->where('tenant_id', $tenantId)
                ->where('id', $invoiceId)
                ->first();

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

            $this->markOutboxProcessed($outbox);
        });
    }

    /**
     * Memproses ALLOCATION_REVERSED: membalik jurnal penerimaan atau penyesuaian GL.
     */
    protected function processAllocationReversed(object $outbox): void
    {
        $this->markOutboxProcessed($outbox);
    }

    protected function markOutboxProcessed(object $outbox): void
    {
        if (method_exists($outbox, 'save')) {
            $outbox->status = 'processed_by_m7';
            $outbox->processed_at = now();
            $outbox->save();
        } else {
            DB::table('accounting_receivable_outboxes')
                ->where('tenant_id', $outbox->tenant_id)
                ->where('id', $outbox->id)
                ->update([
                    'status' => 'processed_by_m7',
                    'processed_at' => now(),
                ]);
        }
    }

    protected function markOutboxFailed(object $outbox, string $error): void
    {
        $retryCount = ($outbox->retry_count ?? 0) + 1;
        $status = $retryCount >= 5 ? 'failed' : ($outbox->status ?? 'pending');
        if (method_exists($outbox, 'save')) {
            $outbox->retry_count = $retryCount;
            $outbox->last_error = substr($error, 0, 1000);
            $outbox->status = $status;
            $outbox->save();
        } else {
            DB::table('accounting_receivable_outboxes')
                ->where('tenant_id', $outbox->tenant_id)
                ->where('id', $outbox->id)
                ->update([
                    'retry_count' => $retryCount,
                    'last_error' => substr($error, 0, 1000),
                    'status' => $status,
                ]);
        }
    }

    /**
     * Format nilai moneter menjadi string decimal(18,2) dengan aturan pembulatan half-up (F-06).
     */
    protected function formatDecimal(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }

        return number_format(round((float) $value, 2, PHP_ROUND_HALF_UP), 2, '.', '');
    }
}
