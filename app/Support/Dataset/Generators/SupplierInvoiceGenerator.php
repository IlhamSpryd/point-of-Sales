<?php

declare(strict_types=1);

namespace App\Support\Dataset\Generators;

use App\Support\Dataset\DatasetConfig;
use App\Support\Dataset\DatasetWriter;
use App\Support\Dataset\SeededRandom;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Supplier AP lifecycle untuk dataset demo (mengikuti skema aktual):
 *
 *   purchase_orders (closed, fully_received) + goods_received_notes (POSTED)
 *     → supplier_invoices (posted) + supplier_invoice_lines
 *     → supplier_invoice_grn_matches (3-way match per GRN line)
 *     → pembayaran pemasok (full / sebagian) → settlement_status + outstanding
 *     → purchase_orders.invoicing_status = fully_invoiced / partially_invoiced
 *     → accounting_payable_outbox (event AP, source: supplier_invoice)
 *
 * Tidak ada jurnal sintetis — hanya event outbox yang dihasilkan aplikasi.
 */
final class SupplierInvoiceGenerator
{
    public function __construct(
        private readonly DatasetConfig $config,
        private readonly DatasetWriter $writer,
    ) {}

    /**
     * Faktur pemasok dari GRN POSTED yang belum di-invoice-kan.
     *
     * @return array{invoices:int,lines:int,matches:int,payments:int}
     */
    public function generate(int $tenantId, int $staffUserId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rng = (new SeededRandom($this->config->seed))->derive("apinv:{$tenantId}:{$from->format('Ym')}");

        $grns = DB::table('goods_received_notes as grn')
            ->leftJoin('supplier_invoices as si', function ($j) {
                // SKEMA AKTUAL: supplier_invoices tidak punya goods_received_note_id.
                // 1 PO biasanya 1 faktur, jadi join lewat purchase_order_id.
                $j->on('si.purchase_order_id', '=', 'grn.purchase_order_id');
            })
            ->where('grn.tenant_id', $tenantId)
            ->where('grn.status', 'POSTED')
            ->whereBetween('grn.received_at', [$from->toDateString(), $to->toDateString()])
            ->whereNull('si.id')
            ->whereNotNull('grn.purchase_order_id')
            ->groupBy('grn.id', 'grn.tenant_id', 'grn.store_id', 'grn.grn_number', 'grn.purchase_order_id', 'grn.received_at', 'grn.received_by_user_id')
            ->get([
                'grn.id', 'grn.tenant_id', 'grn.store_id', 'grn.grn_number',
                'grn.purchase_order_id', 'grn.received_at', 'grn.received_by_user_id',
            ]);

        if ($grns->isEmpty()) {
            return ['invoices' => 0, 'lines' => 0, 'matches' => 0, 'payments' => 0];
        }

        $counts = ['invoices' => 0, 'lines' => 0, 'matches' => 0, 'payments' => 0];
        $terms = DB::table('suppliers')->where('tenant_id', $tenantId)
            ->pluck('payment_terms_days', 'id');

        foreach ($grns as $grn) {
            $idem = $this->config->marker("apinv:{$grn->id}");
            if (DB::table('supplier_invoices')->where('tenant_id', $tenantId)
                ->where('idempotency_key', $idem)->exists()) {
                continue;
            }

            $po = DB::table('purchase_orders')
                ->where('tenant_id', $tenantId)->where('id', $grn->purchase_order_id)->first();
            if (! $po) {
                continue;
            }

            // Baris penerimaan dari GRN lines aktual (bukan goods_receipt_items).
            $grnLines = DB::table('goods_received_note_lines')
                ->where('tenant_id', $tenantId)
                ->where('goods_received_note_id', $grn->id)
                ->get();

            if ($grnLines->isEmpty()) {
                continue;
            }

            $receivedAt = CarbonImmutable::parse($grn->received_at);
            $late = $rng->bool(0.02); // faktur datang lambat
            $invoiceAt = $receivedAt->addDays($late ? $rng->int(5, 12) : $rng->int(0, 2));
            $dueAt = $invoiceAt->addDays((int) ($terms[$po->supplier_id] ?? 7));

            $subtotal = 0.0;
            $lines = [];
            $matches = [];
            $lineNo = 0;

            foreach ($grnLines as $gl) {
                $qty = (float) ($gl->accepted_qty ?? $gl->received_qty);
                $unit = (float) ($gl->effective_unit_cost ?? $gl->unit_cost_snapshot);
                if ($qty <= 0) {
                    continue;
                }

                // Invoiced qty bisa < accepted (variance) bila faktur lambat/kurang.
                $invQty = $late && $rng->bool(0.35)
                    ? round($qty * $rng->floatBetween(0.9, 0.99), 4)
                    : $qty;
                $lineTotal = round($invQty * $unit, 2);
                $subtotal += $lineTotal;
                $lineNo++;

                $expected = round($qty * $unit, 2);
                $priceVar = round($lineTotal - $expected, 2);
                $qtyVar = round($invQty - $qty, 4);

                $lines[] = [
                    'tenant_id' => $tenantId,
                    'supplier_invoice_id' => 0,
                    'purchase_order_line_id' => $gl->purchase_order_line_id,
                    'line_number' => $lineNo,
                    'purchasable_type' => $gl->purchasable_type,
                    'purchasable_id' => $gl->purchasable_id,
                    'invoiced_qty' => $invQty,
                    'unit_price' => $unit,
                    'discount_amount' => 0,
                    'tax_rate' => 0,
                    'tax_amount' => 0,
                    'line_total' => $lineTotal,
                    'notes' => null,
                    'created_at' => $invoiceAt,
                    'updated_at' => $invoiceAt,
                ];

                $matches[] = [
                    'tenant_id' => $tenantId,
                    'supplier_invoice_id' => 0,
                    'supplier_invoice_line_id' => 0,
                    'goods_received_note_line_id' => $gl->id,
                    'matched_qty' => $invQty,
                    'po_unit_price' => $unit,
                    'invoice_unit_price' => $unit,
                    'qty_variance' => $qtyVar,
                    'price_variance_amount' => $priceVar,
                    'tax_variance_amount' => 0,
                    'variance_status' => (abs($qtyVar) > 0.001 || abs($priceVar) > 0.01) ? 'within_tolerance' : 'matched',
                    'is_variance_approved' => (abs($qtyVar) > 0.001 || abs($priceVar) > 0.01) ? 1 : 0,
                    'variance_approved_by_user_id' => (abs($qtyVar) > 0.001 || abs($priceVar) > 0.01) ? $staffUserId : null,
                    'variance_approval_notes' => (abs($qtyVar) > 0.001 || abs($priceVar) > 0.01) ? 'Disetujui otomatis (dataset)' : null,
                    'created_at' => $invoiceAt,
                    'updated_at' => $invoiceAt,
                ];
            }

            if ($lines === []) {
                continue;
            }

            $invoiceNumber = sprintf('SI-%s', $grn->grn_number);
            $invoiceTotal = round($subtotal, 2);

            $invoiceId = (int) DB::table('supplier_invoices')->insertGetId([
                'tenant_id' => $tenantId,
                'store_id' => $grn->store_id,
                'supplier_id' => $po->supplier_id,
                'purchase_order_id' => $po->id,
                'internal_invoice_number' => $invoiceNumber,
                'supplier_invoice_number' => 'INV-'.strtoupper(substr(md5($invoiceNumber), 0, 10)),
                'normalized_supplier_invoice_number' => strtolower('INV-'.strtoupper(substr(md5($invoiceNumber), 0, 10))),
                'invoice_date' => $invoiceAt->toDateString(),
                'due_date' => $dueAt->toDateString(),
                'currency_code' => 'IDR',
                'exchange_rate' => 1,
                'subtotal_amount' => $invoiceTotal,
                'discount_amount' => 0,
                'tax_amount' => 0,
                'additional_charges_amount' => 0,
                'total_amount' => $invoiceTotal,
                'status' => 'approved_payable', // chk_sinv_status: draft/submitted/variance_hold/approved_payable/disputed/cancelled
                'matching_status' => 'matched',
                'settlement_status' => 'unpaid',
                'outstanding_amount' => $invoiceTotal,
                'notes' => 'Faktur pemasok dataset demo',
                'idempotency_key' => $idem,
                'created_at' => $invoiceAt,
                'updated_at' => $invoiceAt,
            ]);
            $counts['invoices']++;

            // Lines (dengan id supaya match bisa merujuk).
            // FK sinvl_invoice_composite_fk → supplier_invoice_id wajib
            // diisi ID faktur yang baru dibuat (sebelumnya masih 0).
            foreach ($lines as &$line) {
                $line['supplier_invoice_id'] = $invoiceId;
            }
            unset($line);

            foreach (array_chunk($lines, $this->writer->batchSize()) as $chunk) {
                DB::table('supplier_invoice_lines')->insert($chunk);
                $this->writer->markWritten('supplier_invoice_lines', count($chunk));
            }
            $counts['lines'] += count($lines);
            $lineIds = DB::table('supplier_invoice_lines')
                ->where('tenant_id', $tenantId)
                ->where('supplier_invoice_id', $invoiceId)
                ->orderBy('line_number')
                ->pluck('id')->all();

            foreach ($matches as $idx => &$match) {
                $match['supplier_invoice_id'] = $invoiceId;
                $match['supplier_invoice_line_id'] = $lineIds[$idx] ?? null;
            }
            unset($match);

            foreach (array_chunk($matches, $this->writer->batchSize()) as $chunk) {
                DB::table('supplier_invoice_grn_matches')->insert($chunk);
                $this->writer->markWritten('supplier_invoice_grn_matches', count($chunk));
            }
            $counts['matches'] += count($matches);

            // Pembayaran: mayoritas lunas sebelum jatuh tempo.
            $payAt = $dueAt->subDays($rng->int(0, 4));
            $datasetEnd = $this->config->effectiveEndDate();

            if ($payAt->lessThanOrEqualTo($datasetEnd)) {
                $isFull = ! $rng->bool(0.10);
                $payAmount = $isFull
                    ? $invoiceTotal
                    : round($invoiceTotal * $rng->floatBetween(0.4, 0.7), 2);
                $settled = $isFull ? 'paid' : 'partially_paid';

                DB::table('accounting_payable_outbox')->insert([
                    'event_id' => $this->config->marker("apout:{$invoiceId}"),
                    'tenant_id' => $tenantId,
                    'store_id' => $grn->store_id,
                    'event_type' => 'supplier_invoice_payment_recorded',
                    'idempotency_key' => $this->config->marker("apoutpay:{$invoiceId}"),
                    'source_document_type' => 'supplier_invoice',
                    'source_document_id' => $invoiceId,
                    'source_document_number' => $invoiceNumber,
                    'status' => 'processed_by_m7',
                    'payload' => json_encode([
                        'amount' => $payAmount,
                        'paid_at' => $payAt->toDateString(),
                        'settled_status' => $settled,
                        'payment_method' => $rng->weighted(['bank_transfer' => 70, 'cash' => 20, 'cheque' => 10]),
                        'payment_reference' => 'PAY-'.strtoupper(substr(md5($invoiceId.'pay'), 0, 12)),
                        'source' => 'dataset-generator',
                    ]),
                    'processed_at' => $payAt,
                    'created_at' => $payAt,
                    'updated_at' => $payAt,
                ]);
                $counts['payments']++;

                DB::table('supplier_invoices')->where('id', $invoiceId)->update([
                    'settlement_status' => $settled,
                    'outstanding_amount' => round($invoiceTotal - $payAmount, 2),
                    'approved_payable_at' => $payAt,
                    'approved_payable_by_user_id' => $staffUserId,
                    'updated_at' => $payAt,
                ]);
            }

            DB::table('purchase_orders')->where('id', $po->id)->update([
                'invoicing_status' => 'fully_invoiced',
                'updated_at' => $invoiceAt,
            ]);
        }

        return $counts;
    }
}
