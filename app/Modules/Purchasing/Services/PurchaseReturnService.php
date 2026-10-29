<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Core\Context\TenantContext;
use App\Enums\DebitNoteStatusEnum;
use App\Enums\GrnStatusEnum;
use App\Enums\IngredientStockMovementTypeEnum;
use App\Enums\InvoiceSettlementStatusEnum;
use App\Enums\PurchaseReturnStatusEnum;
use App\Enums\StockMovementType;
use App\Enums\SupplierInvoiceStatusEnum;
use App\Models\User;
use App\Modules\Inventory\Services\InventoryLedgerService;
use App\Modules\Purchasing\Models\AccountingPayableOutbox;
use App\Modules\Purchasing\Models\GoodsReceivedNote;
use App\Modules\Purchasing\Models\GoodsReceivedNoteLine;
use App\Modules\Purchasing\Models\PurchaseDebitNote;
use App\Modules\Purchasing\Models\PurchaseDebitNoteApplication;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Models\PurchaseReturn;
use App\Modules\Purchasing\Models\PurchaseReturnLine;
use App\Modules\Purchasing\Models\SupplierInvoice;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * PurchaseReturnService
 *
 * Layanan orkestrasi domain untuk retur pembelian ke pemasok dan penerbitan nota debit.
 * Menegakkan:
 * - Batas kuantitas yang dapat diretur (eligible_returnable_qty <= accepted_qty - sum(returned_qty))
 * - Pembalikan stok keluar melalui M4 dengan perlindungan saldo non-negatif (INV-M4-05)
 * - Otomasi penerbitan PurchaseDebitNote berstatus issued
 * - Pengaplikasian nota debit ke SupplierInvoice berstatus approved_payable
 * - Publikasi kontrak integrasi ke AccountingPayableOutbox (Batas Modul 7)
 */
class PurchaseReturnService
{
    public function __construct(
        protected DocumentSequenceService $sequenceService,
        protected InventoryLedgerService $inventoryLedgerService
    ) {}

    /**
     * Membuat draf retur pembelian berdasarkan GRN yang telah diposting.
     *
     * @param  array{store_id: int, supplier_id: int, goods_received_note_id: int, reason: string, notes?: string|null, idempotency_key?: string|null}  $header
     * @param  array<int, array{goods_received_note_line_id: int, return_qty: string|float, reason?: string|null, tax_rate?: string|float|null}>  $lines
     *
     * @throws ValidationException
     */
    public function createPurchaseReturn(array $header, array $lines, User $actor): PurchaseReturn
    {
        if (! $actor->can('create', PurchaseReturn::class)) {
            throw new DomainException('Pengguna tidak memiliki izin untuk membuat retur pembelian (purchasing.return.create).');
        }

        $tenantId = app(TenantContext::class)->getTenantId();
        if ($tenantId === null) {
            throw new DomainException('TenantContext tidak valid.');
        }

        if (empty($lines)) {
            throw ValidationException::withMessages([
                'lines' => ['Minimal satu baris retur barang wajib disertakan.'],
            ]);
        }

        return DB::transaction(function () use ($header, $lines, $actor, $tenantId) {
            /** @var GoodsReceivedNote $grn */
            $grn = GoodsReceivedNote::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $header['goods_received_note_id'])
                ->firstOrFail();

            if ($grn->status !== GrnStatusEnum::Posted) {
                throw ValidationException::withMessages([
                    'goods_received_note_id' => ['Retur pembelian hanya dapat dibuat dari GRN yang berstatus posted.'],
                ]);
            }

            if ((int) $grn->supplier_id !== (int) $header['supplier_id']) {
                throw ValidationException::withMessages([
                    'supplier_id' => ['Supplier retur harus sesuai dengan supplier pada GRN.'],
                ]);
            }

            $storeId = (int) $header['store_id'];

            // Hasilkan nomor dokumen retur sekuensial
            $returnNumber = $this->sequenceService->nextNumber(
                tenantId: $tenantId,
                docType: DocumentSequenceService::TYPE_PURCHASE_RETURN,
                storeId: $storeId
            );

            $purchaseReturn = new PurchaseReturn;
            $purchaseReturn->tenant_id = $tenantId;
            $purchaseReturn->store_id = $storeId;
            $purchaseReturn->supplier_id = (int) $header['supplier_id'];
            $purchaseReturn->goods_received_note_id = $grn->id;
            $purchaseReturn->return_number = $returnNumber;
            $purchaseReturn->status = PurchaseReturnStatusEnum::Draft;
            $purchaseReturn->reason = $header['reason'];
            $purchaseReturn->notes = $header['notes'] ?? null;
            $purchaseReturn->created_by = $actor->id;
            $purchaseReturn->idempotency_key = $header['idempotency_key'] ?? null;
            $purchaseReturn->subtotal_amount = '0.00';
            $purchaseReturn->tax_amount = '0.00';
            $purchaseReturn->total_amount = '0.00';
            $purchaseReturn->save();

            $totalSubtotal = '0.00';
            $totalTax = '0.00';
            $lineNumber = 1;

            foreach ($lines as $lineData) {
                $grnLineId = (int) $lineData['goods_received_note_line_id'];
                $returnQty = (string) $lineData['return_qty'];

                if (bccomp($returnQty, '0', 4) <= 0) {
                    throw ValidationException::withMessages([
                        "lines.{$lineNumber}.return_qty" => ["Kuantitas retur pada baris {$lineNumber} harus lebih besar dari 0."],
                    ]);
                }

                /** @var GoodsReceivedNoteLine $grnLine */
                $grnLine = GoodsReceivedNoteLine::query()
                    ->where('tenant_id', $tenantId)
                    ->where('goods_received_note_id', $grn->id)
                    ->where('id', $grnLineId)
                    ->firstOrFail();

                // Hitung kuantitas yang sudah diretur sebelumnya dari GRN line ini
                $alreadyReturned = (string) PurchaseReturnLine::query()
                    ->where('tenant_id', $tenantId)
                    ->where('goods_received_note_line_id', $grnLine->id)
                    ->whereHas('purchaseReturn', function ($q) {
                        $q->where('status', '!=', PurchaseReturnStatusEnum::Cancelled->value);
                    })
                    ->sum('return_qty');

                $maxReturnable = bcsub((string) $grnLine->accepted_qty, $alreadyReturned, 4);
                if (bccomp($returnQty, $maxReturnable, 4) > 0) {
                    throw ValidationException::withMessages([
                        "lines.{$lineNumber}.return_qty" => [
                            "Kuantitas retur ({$returnQty}) melebihi sisa yang dapat diretur ({$maxReturnable}) untuk baris GRN #{$grnLine->id}.",
                        ],
                    ]);
                }

                $unitCost = (string) $grnLine->effective_unit_cost;
                $lineSubtotal = bcmul($returnQty, $unitCost, 2);

                $taxRate = isset($lineData['tax_rate']) ? (string) $lineData['tax_rate'] : '0.0000';
                $lineTax = bcmul($lineSubtotal, $taxRate, 2);
                $lineTotal = bcadd($lineSubtotal, $lineTax, 2);

                $returnLine = new PurchaseReturnLine;
                $returnLine->tenant_id = $tenantId;
                $returnLine->purchase_return_id = $purchaseReturn->id;
                $returnLine->goods_received_note_line_id = $grnLine->id;
                $returnLine->purchase_order_line_id = $grnLine->purchase_order_line_id;
                $returnLine->line_number = $lineNumber++;
                $returnLine->purchasable_type = $grnLine->purchasable_type;
                $returnLine->purchasable_id = $grnLine->purchasable_id;
                $returnLine->return_qty = $returnQty;
                $returnLine->unit_cost = $unitCost;
                $returnLine->subtotal = $lineSubtotal;
                $returnLine->tax_rate = $taxRate;
                $returnLine->tax_amount = $lineTax;
                $returnLine->total_amount = $lineTotal;
                $returnLine->reason = $lineData['reason'] ?? $header['reason'];
                $returnLine->save();

                $totalSubtotal = bcadd($totalSubtotal, $lineSubtotal, 2);
                $totalTax = bcadd($totalTax, $lineTax, 2);
            }

            $purchaseReturn->subtotal_amount = $totalSubtotal;
            $purchaseReturn->tax_amount = $totalTax;
            $purchaseReturn->total_amount = bcadd($totalSubtotal, $totalTax, 2);
            $purchaseReturn->save();

            return $purchaseReturn->load('lines');
        });
    }

    /**
     * Memposting retur pembelian:
     * 1. Kunci eksklusif baris GRN dan PurchaseReturn
     * 2. Validasi sisa kuantitas retur
     * 3. Eksekusi mutasi pengeluaran stok di M4 via InventoryLedgerService (memicu pengecekan stok non-negatif INV-M4-05)
     * 4. Perbarui returned_qty pada PurchaseOrderLine
     * 5. Otomatis terbitkan PurchaseDebitNote berstatus issued
     *
     * @return array{purchase_return: PurchaseReturn, debit_note: PurchaseDebitNote}
     *
     * @throws ValidationException
     */
    public function postPurchaseReturn(int $returnId, User $actor, ?string $idempotencyKey = null): array
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        if ($tenantId === null) {
            throw new DomainException('TenantContext tidak valid.');
        }

        return DB::transaction(function () use ($returnId, $actor, $idempotencyKey, $tenantId) {
            /** @var PurchaseReturn $lockedReturn */
            $lockedReturn = PurchaseReturn::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($returnId);

            if (! $actor->can('post', $lockedReturn)) {
                throw new DomainException('Pengguna tidak memiliki izin untuk memposting retur pembelian (purchasing.return.post).');
            }

            // Penanganan Idempotensi
            if ($idempotencyKey !== null && $lockedReturn->idempotency_key === $idempotencyKey && $lockedReturn->status === PurchaseReturnStatusEnum::Posted) {
                /** @var PurchaseDebitNote $existingDn */
                $existingDn = PurchaseDebitNote::query()
                    ->where('tenant_id', $tenantId)
                    ->where('purchase_return_id', $lockedReturn->id)
                    ->firstOrFail();

                return [
                    'purchase_return' => $lockedReturn,
                    'debit_note' => $existingDn,
                ];
            }

            if ($lockedReturn->status !== PurchaseReturnStatusEnum::Draft) {
                throw ValidationException::withMessages([
                    'status' => ["Hanya retur berstatus draft yang dapat diposting. Status saat ini: {$lockedReturn->status->value}."],
                ]);
            }

            $returnLines = $lockedReturn->lines()->orderBy('id', 'asc')->get();
            if ($returnLines->isEmpty()) {
                throw ValidationException::withMessages([
                    'lines' => ['Retur pembelian tidak memiliki baris item.'],
                ]);
            }

            // Kunci baris GRN secara deterministik
            $grnLineIds = $returnLines->pluck('goods_received_note_line_id')->unique()->sort()->values();
            $lockedGrnLines = GoodsReceivedNoteLine::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $grnLineIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // 1. Verifikasi batas kuantitas yang dapat diretur per baris GRN
            foreach ($returnLines as $rLine) {
                /** @var GoodsReceivedNoteLine|null $grnLine */
                $grnLine = $lockedGrnLines->get($rLine->goods_received_note_line_id);
                if (! $grnLine) {
                    throw ValidationException::withMessages([
                        'lines' => ["Baris GRN #{$rLine->goods_received_note_line_id} tidak ditemukan."],
                    ]);
                }

                $alreadyReturnedOther = (string) PurchaseReturnLine::query()
                    ->where('tenant_id', $tenantId)
                    ->where('goods_received_note_line_id', $grnLine->id)
                    ->where('purchase_return_id', '!=', $lockedReturn->id)
                    ->whereHas('purchaseReturn', function ($q) {
                        $q->where('status', PurchaseReturnStatusEnum::Posted->value);
                    })
                    ->sum('return_qty');

                $maxAvailable = bcsub((string) $grnLine->accepted_qty, $alreadyReturnedOther, 4);
                if (bccomp((string) $rLine->return_qty, $maxAvailable, 4) > 0) {
                    throw ValidationException::withMessages([
                        'lines' => [
                            "Kuantitas retur ({$rLine->return_qty}) melebihi kuantitas GRN yang tersisa ({$maxAvailable}) untuk baris #{$grnLine->id}.",
                        ],
                    ]);
                }
            }

            // 2. Siapkan mutasi pengeluaran stok di M4 (supplier_return_out)
            $movementItems = [];
            foreach ($returnLines as $rLine) {
                /** @var GoodsReceivedNoteLine $grnLine */
                $grnLine = $lockedGrnLines->get($rLine->goods_received_note_line_id);
                $itemType = $rLine->purchasable_type === 'product' ? 'product' : 'ingredient';

                $movementItems[] = [
                    'store_id' => $lockedReturn->store_id,
                    'item_type' => $itemType,
                    'item_id' => (int) $rLine->purchasable_id,
                    'quantity_delta' => bcmul((string) $rLine->return_qty, '-1', 4),
                    'type' => $itemType === 'product'
                        ? StockMovementType::SupplierReturnOut->value
                        : IngredientStockMovementTypeEnum::SupplierReturnOut->value,
                    'unit_cost' => (string) $rLine->unit_cost,
                    'reason' => "Retur Pembelian #{$lockedReturn->return_number}: {$lockedReturn->reason}",
                    'idempotency_key' => "pret:post:{$tenantId}:{$lockedReturn->id}:{$rLine->id}",
                    'user_id' => $actor->id,
                ];
            }

            // Eksekusi mutasi batch di M4 (memicu pengecekan stok non-negatif INV-M4-05 secara atomik)
            $batchResults = $this->inventoryLedgerService->recordBatchMovements($tenantId, $movementItems);

            // Hubungkan ID mutasi ke baris retur
            foreach ($returnLines as $index => $rLine) {
                if (isset($batchResults[$index])) {
                    $mov = $batchResults[$index]['movement'];
                    if ($rLine->purchasable_type === 'product') {
                        $rLine->stock_movement_id = $mov->id;
                    } else {
                        $rLine->ingredient_stock_movement_id = $mov->id;
                    }
                    $rLine->save();
                }

                // Update returned_qty pada PurchaseOrderLine jika ada
                if ($rLine->purchase_order_line_id !== null) {
                    /** @var PurchaseOrderLine|null $poLine */
                    $poLine = PurchaseOrderLine::query()
                        ->where('tenant_id', $tenantId)
                        ->where('id', $rLine->purchase_order_line_id)
                        ->lockForUpdate()
                        ->first();

                    if ($poLine) {
                        $poLine->returned_qty = bcadd((string) $poLine->returned_qty, (string) $rLine->return_qty, 4);
                        $poLine->save();
                    }
                }
            }

            // 3. Perbarui status PurchaseReturn menjadi posted
            $lockedReturn->status = PurchaseReturnStatusEnum::Posted;
            $lockedReturn->posted_at = now();
            $lockedReturn->posted_by = $actor->id;
            $lockedReturn->returned_at = now();
            if ($idempotencyKey !== null) {
                $lockedReturn->idempotency_key = $idempotencyKey;
            }
            $lockedReturn->save();

            // 4. Terbitkan PurchaseDebitNote secara otomatis
            $debitNoteNumber = $this->sequenceService->nextNumber(
                tenantId: $tenantId,
                docType: DocumentSequenceService::TYPE_DEBIT_NOTE,
                storeId: $lockedReturn->store_id
            );

            $debitNote = new PurchaseDebitNote;
            $debitNote->tenant_id = $tenantId;
            $debitNote->store_id = $lockedReturn->store_id;
            $debitNote->supplier_id = $lockedReturn->supplier_id;
            $debitNote->purchase_return_id = $lockedReturn->id;
            $debitNote->debit_note_number = $debitNoteNumber;
            $debitNote->debit_note_date = now()->startOfDay();
            $debitNote->currency_code = 'IDR';
            $debitNote->subtotal_amount = $lockedReturn->subtotal_amount;
            $debitNote->tax_amount = $lockedReturn->tax_amount;
            $debitNote->total_amount = $lockedReturn->total_amount;
            $debitNote->applied_amount = '0.00';
            $debitNote->remaining_amount = $lockedReturn->total_amount;
            $debitNote->status = DebitNoteStatusEnum::Issued;
            $debitNote->reason = "Diterbitkan otomatis dari Retur Pembelian #{$lockedReturn->return_number}";
            $debitNote->created_by = $actor->id;
            $debitNote->issued_at = now();
            $debitNote->idempotency_key = "dn:pret:{$tenantId}:{$lockedReturn->id}";
            $debitNote->save();

            return [
                'purchase_return' => $lockedReturn->fresh(['lines']),
                'debit_note' => $debitNote,
            ];
        });
    }

    /**
     * Mengaplikasikan nota debit pembelian ke faktur pemasok untuk memotong nilai hutang dagang.
     * Menerbitkan event ke accounting_payable_outbox (Batas Modul 7 - AT-17).
     *
     * @throws ValidationException
     */
    public function applyDebitNoteToInvoice(
        int $debitNoteId,
        int $supplierInvoiceId,
        string $appliedAmount,
        User $actor,
        ?string $notes = null
    ): PurchaseDebitNoteApplication {
        if (! $actor->can('manageDebitNote', PurchaseReturn::class)) {
            throw new DomainException('Pengguna tidak memiliki izin untuk mengelola nota debit (purchasing.debit_note.manage).');
        }

        $tenantId = app(TenantContext::class)->getTenantId();
        if ($tenantId === null) {
            throw new DomainException('TenantContext tidak valid.');
        }

        if (bccomp($appliedAmount, '0', 2) <= 0) {
            throw ValidationException::withMessages([
                'applied_amount' => ['Nominal aplikasi nota debit harus lebih besar dari 0.'],
            ]);
        }

        return DB::transaction(function () use ($debitNoteId, $supplierInvoiceId, $appliedAmount, $actor, $notes, $tenantId) {
            /** @var PurchaseDebitNote $lockedDebitNote */
            $lockedDebitNote = PurchaseDebitNote::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($debitNoteId);

            if (! in_array($lockedDebitNote->status, [DebitNoteStatusEnum::Issued, DebitNoteStatusEnum::PartiallyApplied], true)) {
                throw ValidationException::withMessages([
                    'debit_note_id' => ["Nota debit tidak dalam status yang dapat diaplikasikan. Status: {$lockedDebitNote->status->value}."],
                ]);
            }

            if (bccomp($appliedAmount, (string) $lockedDebitNote->remaining_amount, 2) > 0) {
                throw ValidationException::withMessages([
                    'applied_amount' => [
                        "Nominal yang diaplikasikan ({$appliedAmount}) melebihi sisa nilai nota debit ({$lockedDebitNote->remaining_amount}).",
                    ],
                ]);
            }

            /** @var SupplierInvoice $lockedInvoice */
            $lockedInvoice = SupplierInvoice::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($supplierInvoiceId);

            if ($lockedInvoice->status !== SupplierInvoiceStatusEnum::ApprovedPayable) {
                throw ValidationException::withMessages([
                    'supplier_invoice_id' => [
                        "Nota debit hanya dapat diaplikasikan ke faktur pemasok yang berstatus approved_payable. Status saat ini: {$lockedInvoice->status->value}.",
                    ],
                ]);
            }

            if ((int) $lockedDebitNote->supplier_id !== (int) $lockedInvoice->supplier_id) {
                throw ValidationException::withMessages([
                    'supplier_invoice_id' => ['Nota debit dan faktur pemasok harus berasal dari pemasok yang sama.'],
                ]);
            }

            if (bccomp($appliedAmount, (string) $lockedInvoice->outstanding_amount, 2) > 0) {
                throw ValidationException::withMessages([
                    'applied_amount' => [
                        "Nominal yang diaplikasikan ({$appliedAmount}) melebihi sisa tagihan faktur pemasok ({$lockedInvoice->outstanding_amount}).",
                    ],
                ]);
            }

            // 1. Buat rekaman aplikasi
            $application = new PurchaseDebitNoteApplication;
            $application->tenant_id = $tenantId;
            $application->purchase_debit_note_id = $lockedDebitNote->id;
            $application->supplier_invoice_id = $lockedInvoice->id;
            $application->applied_amount = $appliedAmount;
            $application->applied_at = now();
            $application->applied_by = $actor->id;
            $application->notes = $notes;
            $application->save();

            // 2. Perbarui PurchaseDebitNote
            $newDnApplied = bcadd((string) $lockedDebitNote->applied_amount, $appliedAmount, 2);
            $newDnRemaining = bcsub((string) $lockedDebitNote->remaining_amount, $appliedAmount, 2);

            $lockedDebitNote->applied_amount = $newDnApplied;
            $lockedDebitNote->remaining_amount = $newDnRemaining;
            $lockedDebitNote->status = bccomp($newDnRemaining, '0.00', 2) === 0
                ? DebitNoteStatusEnum::FullyApplied
                : DebitNoteStatusEnum::PartiallyApplied;
            $lockedDebitNote->save();

            // 3. Perbarui SupplierInvoice
            $newInvApplied = bcadd((string) $lockedInvoice->debit_note_applied_amount, $appliedAmount, 2);
            $newInvOutstanding = bcsub((string) $lockedInvoice->outstanding_amount, $appliedAmount, 2);

            $lockedInvoice->debit_note_applied_amount = $newInvApplied;
            $lockedInvoice->outstanding_amount = $newInvOutstanding;
            $lockedInvoice->settlement_status = bccomp($newInvOutstanding, '0.00', 2) === 0
                ? InvoiceSettlementStatusEnum::Paid
                : InvoiceSettlementStatusEnum::PartiallyPaid;
            $lockedInvoice->save();

            // 4. Menerbitkan event transaksi ke accounting_payable_outbox (Batas Modul 7 - AT-17)
            $eventId = Str::uuid()->toString();
            $idempotencyKey = "dn:apply:{$tenantId}:{$application->id}";

            AccountingPayableOutbox::create([
                'tenant_id' => $tenantId,
                'event_id' => $eventId,
                'event_type' => 'DEBIT_NOTE_APPLIED',
                'idempotency_key' => $idempotencyKey,
                'store_id' => $lockedInvoice->store_id,
                'source_document_type' => 'purchase_debit_note',
                'source_document_id' => $lockedDebitNote->id,
                'source_document_number' => $lockedDebitNote->debit_note_number,
                'payload' => [
                    'schema_version' => '1.0',
                    'event_type' => 'DEBIT_NOTE_APPLIED',
                    'event_id' => $eventId,
                    'tenant_id' => $tenantId,
                    'store_id' => $lockedInvoice->store_id,
                    'supplier_id' => $lockedDebitNote->supplier_id,
                    'debit_note_id' => $lockedDebitNote->id,
                    'debit_note_number' => $lockedDebitNote->debit_note_number,
                    'supplier_invoice_id' => $lockedInvoice->id,
                    'supplier_invoice_number' => $lockedInvoice->supplier_invoice_number,
                    'applied_amount' => $appliedAmount,
                    'remaining_debit_note_amount' => $newDnRemaining,
                    'remaining_invoice_outstanding_amount' => $newInvOutstanding,
                    'applied_at' => now()->toIso8601String(),
                ],
                'status' => 'pending',
                'retry_count' => 0,
            ]);

            return $application;
        });
    }

    /**
     * Mencatat nomor nota kredit resmi dari pemasok sebagai persetujuan atas nota debit.
     */
    public function acknowledgeSupplierCreditNote(int $debitNoteId, string $creditNoteNumber, User $actor): PurchaseDebitNote
    {
        if (! $actor->can('manageDebitNote', PurchaseReturn::class)) {
            throw new DomainException('Pengguna tidak memiliki izin untuk mengelola nota debit (purchasing.debit_note.manage).');
        }

        $tenantId = app(TenantContext::class)->getTenantId();
        if ($tenantId === null) {
            throw new DomainException('TenantContext tidak valid.');
        }

        return DB::transaction(function () use ($debitNoteId, $creditNoteNumber, $tenantId) {
            /** @var PurchaseDebitNote $debitNote */
            $debitNote = PurchaseDebitNote::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($debitNoteId);

            $debitNote->supplier_credit_note_number = trim($creditNoteNumber);
            $debitNote->save();

            return $debitNote;
        });
    }
}
