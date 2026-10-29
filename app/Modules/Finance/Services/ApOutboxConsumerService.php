<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Finance\DataTransferObjects\FinancialEventDto;
use App\Modules\Finance\Enums\ApEntryTypeEnum;
use App\Modules\Finance\Models\ApLedgerEntry;
use App\Modules\Purchasing\Models\AccountingPayableOutbox;
use App\Modules\Purchasing\Models\SupplierInvoice;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ApOutboxConsumerService
{
    public function __construct(
        protected PostingEngineService $postingEngine
    ) {}

    /**
     * Memproses sejumlah antrean event pending pada accounting_payable_outbox.
     */
    public function processPendingOutbox(?int $tenantId = null, int $limit = 50): int
    {
        $query = AccountingPayableOutbox::withoutTenantScope()
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
                Log::error("Gagal memproses accounting_payable_outbox #{$outbox->id}: {$e->getMessage()}", [
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
     * Memproses satu baris outbox secara atomik dan idempoten.
     */
    public function processOutboxRecord(AccountingPayableOutbox $outbox): void
    {
        switch ($outbox->event_type) {
            case 'purchasing.invoice.approved_payable':
                $this->processApprovedPayable($outbox);
                break;

            case 'DEBIT_NOTE_APPLIED':
                $this->processDebitNoteApplied($outbox);
                break;

            case 'LANDED_COST_ALLOCATED':
                $this->processLandedCostAllocated($outbox);
                break;

            default:
                // Event lain ditandai diproses
                $outbox->status = 'processed_by_m7';
                $outbox->processed_at = now();
                $outbox->save();
                break;
        }
    }

    /**
     * Memproses event purchasing.invoice.approved_payable:
     * - Menulis baris INVOICE_CREDIT ke ap_ledger_entries.
     * - Memposting jurnal GL PR-PUR-03 via PostingEngineService.
     * - Menandai outbox processed_by_m7.
     */
    protected function processApprovedPayable(AccountingPayableOutbox $outbox): void
    {
        $tenantId = (int) $outbox->tenant_id;
        $invoiceId = (int) $outbox->source_document_id;

        /** @var SupplierInvoice|null $invoice */
        $invoice = SupplierInvoice::withoutTenantScope()
            ->with(['lines', 'grnMatches'])
            ->where('tenant_id', $tenantId)
            ->find($invoiceId);

        if (! $invoice) {
            throw new DomainException("SupplierInvoice #{$invoiceId} tidak ditemukan untuk outbox #{$outbox->id}.");
        }

        DB::transaction(function () use ($outbox, $invoice, $tenantId) {
            // 1. Hitung alokasi finansial (3-way match, variance, tax, discount)
            $grnClearingAmount = '0.00';
            $ppvDebitAmount = '0.00';
            $ppvCreditAmount = '0.00';
            $matches = $invoice->grnMatches;

            if ($matches && $matches->count() > 0) {
                foreach ($matches as $m) {
                    $matchValue = bcmul((string) $m->matched_qty, (string) $m->po_unit_price, 2);
                    $grnClearingAmount = bcadd($grnClearingAmount, $matchValue, 2);

                    $var = (string) $m->price_variance_amount;
                    if (bccomp($var, '0.00', 2) > 0) {
                        $ppvDebitAmount = bcadd($ppvDebitAmount, $var, 2);
                    } elseif (bccomp($var, '0.00', 2) < 0) {
                        $absVar = bcmul($var, '-1', 2);
                        $ppvCreditAmount = bcadd($ppvCreditAmount, $absVar, 2);
                    }
                }
            }

            // Sisa subtotal yang tidak lewat GRN diakui langsung ke persediaan/beban
            $matchedExpectedSubtotal = bcsub(bcadd($grnClearingAmount, $ppvDebitAmount, 2), $ppvCreditAmount, 2);
            $directInventoryAmount = bcsub((string) $invoice->subtotal_amount, $matchedExpectedSubtotal, 2);
            if (bccomp($directInventoryAmount, '0.00', 2) < 0) {
                $directInventoryAmount = '0.00';
            }

            // 2. Kunci dan perbarui running balance subledger AP
            $latestEntry = ApLedgerEntry::withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->where('supplier_id', $invoice->supplier_id)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $prevBalance = $latestEntry ? (string) $latestEntry->running_balance : '0.00';
            $newBalance = bcadd($prevBalance, (string) $invoice->total_amount, 2);

            // 3. Catat INVOICE_CREDIT di ap_ledger_entries (idempoten)
            $idempotencyKey = "aple:inv:{$tenantId}:{$invoice->id}";
            $ledgerEntry = ApLedgerEntry::withoutTenantScope()->firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'idempotency_key' => $idempotencyKey,
                ],
                [
                    'store_id' => $invoice->store_id,
                    'supplier_id' => $invoice->supplier_id,
                    'supplier_invoice_id' => $invoice->id,
                    'entry_number' => $invoice->internal_invoice_number,
                    'effective_date' => $invoice->invoice_date->toDateString(),
                    'entry_type' => ApEntryTypeEnum::INVOICE_CREDIT,
                    'debit_amount' => '0.00',
                    'credit_amount' => (string) $invoice->total_amount,
                    'running_balance' => $newBalance,
                    'description' => "Pengakuan Faktur Pemasok #{$invoice->internal_invoice_number}",
                    'created_by_user_id' => $invoice->approved_payable_by_user_id,
                ]
            );

            // 4. Posting Jurnal GL PR-PUR-03 via PostingEngineService
            $eventDto = new FinancialEventDto(
                tenantId: $tenantId,
                storeId: $invoice->store_id,
                sourceModule: 'Purchasing',
                sourceType: 'supplier_invoice',
                sourceId: $invoice->id,
                sourceDocumentNumber: $invoice->internal_invoice_number,
                eventCode: 'purchasing.invoice.approved_payable',
                idempotencyKey: "m5:supplier_invoice_posted:{$tenantId}:{$invoice->id}",
                occurredAt: $outbox->occurred_at ?? now(),
                accountingDate: $invoice->invoice_date->toDateString(),
                payload: [
                    'grn_clearing_amount' => $grnClearingAmount,
                    'direct_inventory_amount' => $directInventoryAmount,
                    'ppv_debit_amount' => $ppvDebitAmount,
                    'ppv_credit_amount' => $ppvCreditAmount,
                    'tax_amount' => (string) $invoice->tax_amount,
                    'discount_amount' => (string) $invoice->discount_amount,
                    'total_amount' => (string) $invoice->total_amount,
                    'supplier_id' => $invoice->supplier_id,
                ],
                userId: $invoice->approved_payable_by_user_id,
            );

            $journal = $this->postingEngine->postEvent($eventDto);

            // 5. Tautkan journal_entry_id ke invoice dan subledger
            if (! $invoice->gl_journal_entry_id) {
                $invoice->gl_journal_entry_id = $journal->id;
                $invoice->save();
            }

            if (! $ledgerEntry->journal_entry_id) {
                $ledgerEntry->journal_entry_id = $journal->id;
                $ledgerEntry->save();
            }

            // 6. Tandai outbox telah selesai diproses oleh M7
            $outbox->status = 'processed_by_m7';
            $outbox->processed_at = now();
            $outbox->save();
        });
    }

    /**
     * Memproses event DEBIT_NOTE_APPLIED:
     * - Menulis baris DEBIT_NOTE_APPLIED ke ap_ledger_entries.
     * - Memposting jurnal GL PR-PUR-05 via PostingEngineService.
     * - Menandai outbox processed_by_m7.
     */
    protected function processDebitNoteApplied(AccountingPayableOutbox $outbox): void
    {
        $tenantId = (int) $outbox->tenant_id;
        $payload = is_array($outbox->payload) ? $outbox->payload : json_decode((string) $outbox->payload, true);

        $supplierId = (int) $payload['supplier_id'];
        $storeId = (int) ($payload['store_id'] ?? $outbox->store_id);
        $appliedAmount = (string) $payload['applied_amount'];
        $debitNoteId = (int) $payload['debit_note_id'];
        $supplierInvoiceId = (int) $payload['supplier_invoice_id'];
        $appliedAt = Carbon::parse($payload['applied_at'] ?? now());

        DB::transaction(function () use ($outbox, $tenantId, $supplierId, $storeId, $appliedAmount, $debitNoteId, $supplierInvoiceId, $appliedAt) {
            // 1. Kunci dan perbarui running balance subledger AP
            $latestEntry = ApLedgerEntry::withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->where('supplier_id', $supplierId)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $prevBalance = $latestEntry ? (string) $latestEntry->running_balance : '0.00';
            $newBalance = bcsub($prevBalance, $appliedAmount, 2);

            // 2. Catat DEBIT_NOTE_APPLIED di ap_ledger_entries
            $idempotencyKey = "aple:dn_apply:{$tenantId}:{$outbox->id}";
            $invExists = $supplierInvoiceId > 0 && SupplierInvoice::withoutTenantScope()->where('tenant_id', $tenantId)->where('id', $supplierInvoiceId)->exists();
            $validInvoiceId = $invExists ? $supplierInvoiceId : null;

            $ledgerEntry = ApLedgerEntry::withoutTenantScope()->firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'idempotency_key' => $idempotencyKey,
                ],
                [
                    'store_id' => $storeId,
                    'supplier_id' => $supplierId,
                    'supplier_invoice_id' => $validInvoiceId,
                    'purchase_debit_note_id' => $debitNoteId,
                    'entry_number' => $outbox->source_document_number,
                    'effective_date' => $appliedAt->toDateString(),
                    'entry_type' => ApEntryTypeEnum::DEBIT_NOTE_APPLIED,
                    'debit_amount' => $appliedAmount,
                    'credit_amount' => '0.00',
                    'running_balance' => $newBalance,
                    'description' => "Aplikasi Nota Debit #{$outbox->source_document_number} ke Faktur #{$supplierInvoiceId}",
                    'created_by_user_id' => null,
                ]
            );

            // 3. Posting Jurnal GL PR-PUR-05 via PostingEngineService
            $eventDto = new FinancialEventDto(
                tenantId: $tenantId,
                storeId: $storeId,
                sourceModule: 'Purchasing',
                sourceType: 'purchase_debit_note',
                sourceId: $debitNoteId,
                sourceDocumentNumber: $outbox->source_document_number,
                eventCode: 'purchasing.debit_note.applied',
                idempotencyKey: "m5:debit_note_applied:{$tenantId}:{$outbox->id}",
                occurredAt: $outbox->occurred_at ?? $appliedAt,
                accountingDate: $appliedAt->toDateString(),
                payload: [
                    'applied_amount' => $appliedAmount,
                    'grn_reversal_amount' => $appliedAmount,
                    'inventory_reversal_amount' => '0.00',
                    'tax_correction_amount' => '0.00',
                    'supplier_id' => $supplierId,
                ],
            );

            $journal = $this->postingEngine->postEvent($eventDto);

            // 4. Tautkan journal_entry_id ke ledgerEntry
            if (! $ledgerEntry->journal_entry_id) {
                $ledgerEntry->journal_entry_id = $journal->id;
                $ledgerEntry->save();
            }

            // 5. Tandai outbox selesai
            $outbox->status = 'processed_by_m7';
            $outbox->processed_at = now();
            $outbox->save();
        });
    }

    /**
     * Memproses event LANDED_COST_ALLOCATED.
     */
    protected function processLandedCostAllocated(AccountingPayableOutbox $outbox): void
    {
        $outbox->status = 'processed_by_m7';
        $outbox->processed_at = now();
        $outbox->save();
    }
}
