<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Enums\SupplierInvoiceStatusEnum;
use App\Models\Supplier;
use App\Modules\Finance\Models\ApLedgerEntry;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Purchasing\Models\GoodsReceivedNoteLine;
use App\Modules\Purchasing\Models\SupplierInvoice;
use App\Modules\Purchasing\Models\SupplierInvoiceGrnMatch;
use Carbon\Carbon;

class ApGlReconciliationService
{
    public function __construct(
        protected ChartOfAccountService $coaService
    ) {}

    /**
     * Menghasilkan laporan AP Aging Schedule berdasarkan tanggal jatuh tempo faktur.
     * Buckets: Current (belum jatuh tempo), 1-30 hari, 31-60 hari, 61-90 hari, >90 hari.
     *
     * @return array<string, mixed>
     */
    public function getAgingReport(int $tenantId, ?int $storeId = null, ?string $asOfDate = null): array
    {
        $asOf = $asOfDate ? Carbon::parse($asOfDate)->startOfDay() : now()->startOfDay();

        $query = SupplierInvoice::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('status', SupplierInvoiceStatusEnum::ApprovedPayable)
            ->where('outstanding_amount', '>', 0);

        if ($storeId !== null) {
            $query->where('store_id', $storeId);
        }

        $invoices = $query->with('supplier')->get();

        $agingBySupplier = [];
        $totalCurrent = '0.00';
        $total1To30 = '0.00';
        $total31To60 = '0.00';
        $total61To90 = '0.00';
        $totalOver90 = '0.00';
        $grandTotal = '0.00';

        foreach ($invoices as $inv) {
            $suppId = $inv->supplier_id;
            $suppName = $inv->supplier?->name ?? "Supplier #{$suppId}";

            if (! isset($agingBySupplier[$suppId])) {
                $agingBySupplier[$suppId] = [
                    'supplier_id' => $suppId,
                    'supplier_name' => $suppName,
                    'current' => '0.00',
                    '1_30_days' => '0.00',
                    '31_60_days' => '0.00',
                    '61_90_days' => '0.00',
                    'over_90_days' => '0.00',
                    'total_outstanding' => '0.00',
                ];
            }

            $outstanding = (string) $inv->outstanding_amount;
            $dueDate = $inv->due_date ? Carbon::parse($inv->due_date)->startOfDay() : $inv->invoice_date;
            $daysOverdue = (int) $dueDate->diffInDays($asOf, false);

            if ($daysOverdue <= 0) {
                $agingBySupplier[$suppId]['current'] = bcadd($agingBySupplier[$suppId]['current'], $outstanding, 2);
                $totalCurrent = bcadd($totalCurrent, $outstanding, 2);
            } elseif ($daysOverdue <= 30) {
                $agingBySupplier[$suppId]['1_30_days'] = bcadd($agingBySupplier[$suppId]['1_30_days'], $outstanding, 2);
                $total1To30 = bcadd($total1To30, $outstanding, 2);
            } elseif ($daysOverdue <= 60) {
                $agingBySupplier[$suppId]['31_60_days'] = bcadd($agingBySupplier[$suppId]['31_60_days'], $outstanding, 2);
                $total31To60 = bcadd($total31To60, $outstanding, 2);
            } elseif ($daysOverdue <= 90) {
                $agingBySupplier[$suppId]['61_90_days'] = bcadd($agingBySupplier[$suppId]['61_90_days'], $outstanding, 2);
                $total61To90 = bcadd($total61To90, $outstanding, 2);
            } else {
                $agingBySupplier[$suppId]['over_90_days'] = bcadd($agingBySupplier[$suppId]['over_90_days'], $outstanding, 2);
                $totalOver90 = bcadd($totalOver90, $outstanding, 2);
            }

            $agingBySupplier[$suppId]['total_outstanding'] = bcadd($agingBySupplier[$suppId]['total_outstanding'], $outstanding, 2);
            $grandTotal = bcadd($grandTotal, $outstanding, 2);
        }

        return [
            'as_of_date' => $asOf->toDateString(),
            'suppliers' => array_values($agingBySupplier),
            'totals' => [
                'current' => $totalCurrent,
                '1_30_days' => $total1To30,
                '31_60_days' => $total31To60,
                '61_90_days' => $total61To90,
                'over_90_days' => $totalOver90,
                'grand_total' => $grandTotal,
            ],
        ];
    }

    /**
     * Melakukan rekonsiliasi antara:
     * 1. Total Outstanding pada seluruh SupplierInvoice aktif.
     * 2. Saldo bersih (Credit - Debit) pada ApLedgerEntry.
     * 3. Saldo akun AP Control (2101) di General Ledger.
     *
     * @return array<string, mixed>
     */
    public function reconcileApToGl(int $tenantId, ?string $asOfDate = null): array
    {
        // 1. Total dari Faktur Pemasok (Supplier Invoices)
        $invoiceTotal = (string) (SupplierInvoice::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('status', SupplierInvoiceStatusEnum::ApprovedPayable)
            ->sum('outstanding_amount') ?? '0.00');
        $invoiceTotal = bcadd($invoiceTotal, '0', 2);

        // 2. Total dari Subledger AP (ap_ledger_entries)
        $subledgerCredit = (string) (ApLedgerEntry::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->sum('credit_amount') ?? '0.00');
        $subledgerDebit = (string) (ApLedgerEntry::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->sum('debit_amount') ?? '0.00');
        $subledgerTotal = bcsub($subledgerCredit, $subledgerDebit, 2);

        // 3. Saldo Akun Kontrol AP di General Ledger
        $apAccount = $this->coaService->getAccountBySystemKey('AP_CONTROL', $tenantId);

        $glCredit = (string) (JournalLine::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('account_id', $apAccount->id)
            ->whereHas('journalEntry', fn ($q) => $q->where('status', 'POSTED'))
            ->sum('credit_amount') ?? '0.00');

        $glDebit = (string) (JournalLine::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('account_id', $apAccount->id)
            ->whereHas('journalEntry', fn ($q) => $q->where('status', 'POSTED'))
            ->sum('debit_amount') ?? '0.00');

        $glTotal = bcsub($glCredit, $glDebit, 2);

        // 4. Hitung selisih
        $diffSubledgerVsInvoices = bcsub($subledgerTotal, $invoiceTotal, 2);
        $diffSubledgerVsGl = bcsub($subledgerTotal, $glTotal, 2);

        $isReconciled = (bccomp($diffSubledgerVsInvoices, '0.00', 2) === 0)
            && (bccomp($diffSubledgerVsGl, '0.00', 2) === 0);

        return [
            'is_reconciled' => $isReconciled,
            'supplier_invoices_outstanding' => $invoiceTotal,
            'ap_subledger_balance' => $subledgerTotal,
            'gl_ap_control_balance' => $glTotal,
            'diff_subledger_vs_invoices' => $diffSubledgerVsInvoices,
            'diff_subledger_vs_gl' => $diffSubledgerVsGl,
        ];
    }

    /**
     * Rekonsiliasi GR/IR Clearing:
     * Membandingkan nilai GRN yang belum difakturkan vs saldo akun GR/IR Clearing di GL.
     *
     * @return array<string, mixed>
     */
    public function reconcileGrnClearing(int $tenantId): array
    {
        // 1. Akun GR_IR_CLEARING di GL
        $grIrAccount = $this->coaService->getAccountBySystemKey('GR_IR_CLEARING', $tenantId);

        $glCredit = (string) (JournalLine::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('account_id', $grIrAccount->id)
            ->whereHas('journalEntry', fn ($q) => $q->where('status', 'POSTED'))
            ->sum('credit_amount') ?? '0.00');

        $glDebit = (string) (JournalLine::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('account_id', $grIrAccount->id)
            ->whereHas('journalEntry', fn ($q) => $q->where('status', 'POSTED'))
            ->sum('debit_amount') ?? '0.00');

        $glGrIrBalance = bcsub($glCredit, $glDebit, 2);

        // 2. Hitung nilai GRN posted yang belum difakturkan secara operasional
        // Baris GRN yang sudah diposting
        $grnLines = GoodsReceivedNoteLine::withoutTenantScope()
            ->with('purchaseOrderLine')
            ->where('tenant_id', $tenantId)
            ->whereHas('goodsReceivedNote', fn ($q) => $q->where('status', 'posted'))
            ->get();

        $totalOperationalUnbilledGrn = '0.00';
        foreach ($grnLines as $gl) {
            $receivedQty = (string) ($gl->accepted_qty ?? $gl->received_qty ?? '0');
            $cost = '0.0000';
            if (! empty($gl->effective_unit_cost) && bccomp((string) $gl->effective_unit_cost, '0.0000', 4) > 0) {
                $cost = (string) $gl->effective_unit_cost;
            } elseif (! empty($gl->unit_cost_snapshot) && bccomp((string) $gl->unit_cost_snapshot, '0.0000', 4) > 0) {
                $cost = (string) $gl->unit_cost_snapshot;
            } elseif ($gl->purchaseOrderLine && ! empty($gl->purchaseOrderLine->unit_price)) {
                $cost = (string) $gl->purchaseOrderLine->unit_price;
            }

            $grnLineTotal = bcmul($receivedQty, $cost, 2);

            // Cek matched qty pada supplier_invoice_grn_matches
            $matchedQty = (string) (SupplierInvoiceGrnMatch::withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->where('goods_received_note_line_id', $gl->id)
                ->whereHas('supplierInvoice', fn ($q) => $q->where('status', SupplierInvoiceStatusEnum::ApprovedPayable))
                ->sum('matched_qty') ?? '0');

            $matchedValue = bcmul($matchedQty, $cost, 2);
            $unmatchedValue = bcsub($grnLineTotal, $matchedValue, 2);

            if (bccomp($unmatchedValue, '0.00', 2) > 0) {
                $totalOperationalUnbilledGrn = bcadd($totalOperationalUnbilledGrn, $unmatchedValue, 2);
            }
        }

        $diff = bcsub($totalOperationalUnbilledGrn, $glGrIrBalance, 2);

        return [
            'is_reconciled' => (bccomp($diff, '0.00', 2) === 0),
            'operational_unbilled_grn_amount' => $totalOperationalUnbilledGrn,
            'gl_gr_ir_clearing_balance' => $glGrIrBalance,
            'difference' => $diff,
        ];
    }
}
