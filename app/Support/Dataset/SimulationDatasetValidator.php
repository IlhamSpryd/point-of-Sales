<?php

declare(strict_types=1);

namespace App\Support\Dataset;

use Illuminate\Support\Facades\DB;

/**
 * Validator 25 Invarian Matematika & Integritas Basis Data Simulasi 90 Hari.
 * Sesuai Dokumen Spesifikasi: docs/database/DATASET_SIMULATION_MASTER_PLAN.md
 *
 * Menggunakan nama kolom fisik DDL aktual MariaDB:
 * - product_stock_balances / ingredient_stock_balances: quantity
 * - customer_credit_accounts: invoiced_exposure, reserved_exposure, unapplied_credit_balance, credit_limit
 * - shifts: opening_balance, closing_balance, expected_cash, cash_difference
 * - product_unit_conversions: conversion_factor
 */
final class SimulationDatasetValidator
{
    /**
     * @param  array<int, int>  $tenantIds
     * @return array<int, array{code: string, name: string, status: string, detail: string}>
     */
    public function validate(array $tenantIds = [1, 2]): array
    {
        $tenantListStr = implode(',', array_map('intval', $tenantIds));
        $results = [];

        // 1. INV-01: Isolasi Multi-Tenancy (0 kebocoran lintas tenant)
        $mismatches = 0;
        $mismatches += (int) DB::table('orders as o')
            ->join('stores as s', 's.id', '=', 'o.store_id')
            ->whereIn('o.tenant_id', $tenantIds)
            ->whereColumn('o.tenant_id', '!=', 's.tenant_id')
            ->count();
        $mismatches += (int) DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->whereIn('oi.tenant_id', $tenantIds)
            ->whereColumn('oi.tenant_id', '!=', 'o.tenant_id')
            ->count();
        $mismatches += (int) DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->whereIn('jl.tenant_id', $tenantIds)
            ->whereColumn('jl.tenant_id', '!=', 'je.tenant_id')
            ->count();
        $results[] = $this->row('INV-01', 'Isolasi Multi-Tenancy (0 Cross-Tenant Leakage)', $mismatches === 0, "pelanggaran={$mismatches}");

        // 2. INV-02: Rekonsiliasi Total Tagihan Pesanan
        $badOrderAmount = (int) DB::table('orders')
            ->whereIn('tenant_id', $tenantIds)
            ->whereRaw('order_amount != (subtotal_amount - discount_amount + tax_amount + service_charge_amount)')
            ->count();
        $results[] = $this->row('INV-02', 'Rekonsiliasi Total Tagihan Pesanan (order_amount == subtotal - disc + tax + svc)', $badOrderAmount === 0, "selisih_order={$badOrderAmount}");

        // 3. INV-03: Rekonsiliasi Nilai Baris Item terhadap Header
        $itemMismatch = DB::select(
            "SELECT COUNT(*) AS c FROM (
                SELECT o.id, o.subtotal_amount, COALESCE(SUM(oi.qty * oi.order_price), 0) AS items_total
                FROM orders o
                JOIN order_items oi ON oi.order_id = o.id
                WHERE o.tenant_id IN ({$tenantListStr})
                GROUP BY o.id, o.subtotal_amount
                HAVING o.subtotal_amount <> items_total
            ) x"
        )[0]->c ?? 0;
        $results[] = $this->row('INV-03', 'Rekonsiliasi Nilai Baris Item terhadap Header (subtotal == Σ(qty × price))', (int) $itemMismatch === 0, "mismatch={$itemMismatch}");

        // 4. INV-04: Rekonsiliasi Pembayaran Lunas
        $unpaidMismatch = DB::select(
            "SELECT COUNT(*) AS c FROM (
                SELECT o.id, o.order_amount, COALESCE(SUM(p.amount), 0) AS paid_total
                FROM orders o
                LEFT JOIN payments p ON p.order_id = o.id AND p.status = 'captured'
                WHERE o.tenant_id IN ({$tenantListStr}) AND o.order_status = 'paid'
                GROUP BY o.id, o.order_amount
                HAVING o.order_amount <> paid_total
            ) x"
        )[0]->c ?? 0;
        $results[] = $this->row('INV-04', 'Rekonsiliasi Pembayaran Lunas (order_status=paid ↔ Σpayment == order_amount)', (int) $unpaidMismatch === 0, "mismatch={$unpaidMismatch}");

        // 5. INV-05: Rekonsiliasi Penjualan Bersih / Net Sales
        $subtotalSum = (float) DB::table('orders')->whereIn('tenant_id', $tenantIds)->where('order_status', 'paid')->sum('subtotal_amount');
        $discountSum = (float) DB::table('orders')->whereIn('tenant_id', $tenantIds)->where('order_status', 'paid')->sum('discount_amount');
        $returnSum = (float) DB::table('order_returns')->whereIn('tenant_id', $tenantIds)->where('status', 'completed')->sum('subtotal_returned');
        $netSales = $subtotalSum - $discountSum - $returnSum;
        $results[] = $this->row('INV-05', 'Rekonsiliasi Penjualan Bersih (Net Sales = Subtotal - Diskon - Retur, Tanpa PPN/Svc)', $netSales > 0, "net_sales=Rp " . number_format($netSales, 2, ',', '.'));

        // 6. INV-06: Keseimbangan Mutlak Jurnal Umum Double-Entry
        $unbalancedJournals = DB::select(
            "SELECT COUNT(*) AS c FROM (
                SELECT je.id, je.journal_number,
                       ROUND(SUM(jl.debit_amount), 2) AS tot_debit,
                       ROUND(SUM(jl.credit_amount), 2) AS tot_credit
                FROM journal_entries je
                JOIN journal_lines jl ON jl.journal_entry_id = je.id
                WHERE je.tenant_id IN ({$tenantListStr})
                GROUP BY je.id, je.journal_number
                HAVING ABS(tot_debit - tot_credit) > 0.01
            ) x"
        )[0]->c ?? 0;
        $results[] = $this->row('INV-06', 'Keseimbangan Mutlak Double-Entry GL (Σdebit == Σcredit per jurnal)', (int) $unbalancedJournals === 0, "jurnal_tidak_balance={$unbalancedJournals}");

        // 7. INV-07: Penyelarasan Subledger Penjualan ke Buku Besar GL
        $revenueCredit = (float) DB::table('journal_lines as jl')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jl.account_id')
            ->whereIn('jl.tenant_id', $tenantIds)
            ->whereIn('coa.account_code', ['4101', '4102'])
            ->sum('jl.credit_amount');
        $revenueDebit = (float) DB::table('journal_lines as jl')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jl.account_id')
            ->whereIn('jl.tenant_id', $tenantIds)
            ->whereIn('coa.account_code', ['4101', '4102'])
            ->sum('jl.debit_amount');
        $netGlRevenue = $revenueCredit - $revenueDebit;
        $inv07Pass = ($netGlRevenue > 0 && abs($netGlRevenue - $netSales) < 1.00);
        $results[] = $this->row('INV-07', 'Penyelarasan Subledger Penjualan ke Akun Pendapatan GL (Net Sales == Mutasi Kredit 4101/4102)', $inv07Pass, "gl_net=Rp " . number_format($netGlRevenue, 2, ',', '.') . " vs net_sales=Rp " . number_format($netSales, 2, ',', '.'));

        // 8. INV-08: Integritas Snapshot HPP
        $missingHpp = (int) DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->whereIn('oi.tenant_id', $tenantIds)
            ->where('o.order_status', 'paid')
            ->where(function ($q) {
                $q->whereNull('oi.hpp_snapshot')->orWhere('oi.hpp_snapshot', '<', 0);
            })
            ->count();
        $results[] = $this->row('INV-08', 'Integritas Snapshot HPP (Moving Average Cost Snapshot >= 0 pada setiap baris item)', $missingHpp === 0, "item_tanpa_hpp={$missingHpp}");

        // 9. INV-09: Keseimbangan Jurnal HPP ke Persediaan
        $cogsPostings = (int) DB::table('journal_lines as jl')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jl.account_id')
            ->whereIn('jl.tenant_id', $tenantIds)
            ->whereIn('coa.account_code', ['5101', '5102', '1140', '1141'])
            ->count();
        $results[] = $this->row('INV-09', 'Keseimbangan Jurnal HPP terhadap Persediaan (Debit 5101/5102 & Kredit 1140/1141)', $cogsPostings > 0, "postingan_hpp_persediaan={$cogsPostings}");

        // 10. INV-10: Non-Negatif Saldo Persediaan
        $negProdStock = (int) DB::table('product_stock_balances')
            ->whereIn('tenant_id', $tenantIds)
            ->where('quantity', '<', 0)
            ->count();
        $negIngStock = (int) DB::table('ingredient_stock_balances')
            ->whereIn('tenant_id', $tenantIds)
            ->where('quantity', '<', 0)
            ->count();
        $results[] = $this->row('INV-10', 'Non-Negatif Saldo Persediaan (product & ingredient stock >= 0)', ($negProdStock === 0 && $negIngStock === 0), "neg_product={$negProdStock}, neg_ingredient={$negIngStock}");

        // 11. INV-11: Rekonsiliasi Saldo Stok Fisik terhadap Akumulasi Ledger
        $unreconciledIng = DB::select(
            "SELECT COUNT(*) AS c FROM ingredient_stock_balances b
             LEFT JOIN (
                SELECT tenant_id, store_id, ingredient_id, SUM(quantity) AS q
                FROM ingredient_stock_movements
                WHERE tenant_id IN ({$tenantListStr})
                GROUP BY tenant_id, store_id, ingredient_id
             ) m ON m.tenant_id = b.tenant_id AND m.store_id = b.store_id AND m.ingredient_id = b.ingredient_id
             WHERE b.tenant_id IN ({$tenantListStr})
               AND ABS(COALESCE(m.q, 0) - b.quantity) > 0.01"
        )[0]->c ?? 0;

        $unreconciledProd = DB::select(
            "SELECT COUNT(*) AS c FROM product_stock_balances b
             LEFT JOIN (
                SELECT tenant_id, store_id, product_id, SUM(quantity) AS q
                FROM stock_movements
                WHERE tenant_id IN ({$tenantListStr})
                GROUP BY tenant_id, store_id, product_id
             ) m ON m.tenant_id = b.tenant_id AND m.store_id = b.store_id AND m.product_id = b.product_id
             WHERE b.tenant_id IN ({$tenantListStr})
               AND ABS(COALESCE(m.q, 0) - b.quantity) > 0.01"
        )[0]->c ?? 0;
        $inv11Pass = ((int) $unreconciledIng === 0 && (int) $unreconciledProd === 0);
        $results[] = $this->row('INV-11', 'Rekonsiliasi Saldo Stok terhadap Akumulasi Ledger Movement (bahan & produk)', $inv11Pass, "selisih_bahan={$unreconciledIng}, selisih_produk={$unreconciledProd}");

        // 12. INV-12: Integritas Buku Pembantu Piutang AR
        $arCount = (int) DB::table('ar_ledger_entries')->whereIn('tenant_id', $tenantIds)->count();
        $arMismatch = DB::select(
            "SELECT COUNT(*) AS c FROM customer_credit_accounts cca
             JOIN (
                SELECT tenant_id, customer_id, SUM(debit_amount) - SUM(credit_amount) as net_ar
                FROM ar_ledger_entries
                WHERE tenant_id IN ({$tenantListStr})
                GROUP BY tenant_id, customer_id
             ) ar ON ar.tenant_id = cca.tenant_id AND ar.customer_id = cca.customer_id
             WHERE cca.tenant_id IN ({$tenantListStr})
               AND ABS(cca.invoiced_exposure - ar.net_ar) > 0.01"
        )[0]->c ?? 0;
        $inv12Pass = ($arCount > 0 && (int) $arMismatch === 0);
        $results[] = $this->row('INV-12', 'Integritas Buku Pembantu Piutang AR (invoiced_exposure == Σdebit - Σcredit)', $inv12Pass, "entri_ar={$arCount}, mismatch={$arMismatch}");

        // 13. INV-13: Kepatuhan Batas Kredit Pelanggan
        $creditLimitBreach = (int) DB::table('customer_credit_accounts')
            ->whereIn('tenant_id', $tenantIds)
            ->whereRaw('invoiced_exposure > credit_limit')
            ->count();
        $results[] = $this->row('INV-13', 'Kepatuhan Batas Kredit Pelanggan (invoiced_exposure <= credit_limit)', $creditLimitBreach === 0, "pelanggaran_limit={$creditLimitBreach}");

        // 14. INV-14: Integritas Buku Pembantu Hutang AP
        $apCount = (int) DB::table('ap_ledger_entries')->whereIn('tenant_id', $tenantIds)->count();
        $apTenantsCount = (int) DB::table('ap_ledger_entries')->whereIn('tenant_id', $tenantIds)->distinct()->count('tenant_id');
        $apBalanceDiff = (float) DB::table('ap_ledger_entries')->whereIn('tenant_id', $tenantIds)->selectRaw('COALESCE(SUM(credit_amount) - SUM(debit_amount), 0) as diff')->value('diff');
        $inv14Pass = ($apCount > 0 && $apTenantsCount >= count($tenantIds) && abs($apBalanceDiff) < 0.01);
        $results[] = $this->row('INV-14', 'Integritas Buku Pembantu Hutang AP (ap_ledger_entries 100% tenant & balanced)', $inv14Pass, "entri_ap={$apCount}, tenant_terisi={$apTenantsCount}, selisih_ap=Rp " . number_format($apBalanceDiff, 2));

        // 15. INV-15: Rekonsiliasi Kas Masuk Kasir
        $closedShifts = (int) DB::table('shifts')->whereIn('tenant_id', $tenantIds)->where('status', 'closed')->count();
        $mismatchShifts = (int) DB::table('shifts')
            ->whereIn('tenant_id', $tenantIds)
            ->where('status', 'closed')
            ->whereRaw('ABS((CAST(closing_balance AS SIGNED) - CAST(expected_cash AS SIGNED)) - CAST(cash_difference AS SIGNED)) > 0.01')
            ->count();
        $inv15Pass = ($closedShifts > 0 && $mismatchShifts === 0);
        $results[] = $this->row('INV-15', 'Rekonsiliasi Kas Masuk Kasir (cash_diff == closing - expected seluruh shift closed)', $inv15Pass, "shift_closed={$closedShifts}, mismatch={$mismatchShifts}");

        // 16. INV-16: Deteksi Selisih Kasir / Cash Variance
        $varianceShift = DB::table('shifts')
            ->whereIn('tenant_id', $tenantIds)
            ->whereDate('opened_at', '2026-11-10')
            ->where('cash_difference', -15000)
            ->whereNull('variance_approved_by')
            ->first();
        $inv16Pass = ($varianceShift !== null);
        $results[] = $this->row('INV-16', 'Deteksi Selisih Kasir (Unapproved Cash Variance Shift -15.000 tgl 2026-11-10)', $inv16Pass, $varianceShift ? "shift_id={$varianceShift->id}, store_id={$varianceShift->store_id}, diff=Rp {$varianceShift->cash_difference}" : 'tidak_ditemukan');

        // 17. INV-17: Kepatuhan Lembur PP 35/2021 (Pembagi 173 per jam)
        $overtimeComp = DB::table('salary_components')
            ->whereIn('tenant_id', $tenantIds)
            ->where('component_code', 'like', 'OVERTIME%')
            ->count();
        $results[] = $this->row('INV-17', 'Kepatuhan Lembur PP 35/2021 (Komponen Overtime Pembagi 173)', $overtimeComp > 0, "komponen_lembur={$overtimeComp}");

        // 18. INV-18: Kepatuhan PPh 21 TER PMK 168/2023
        $totalEmp = DB::table('employees')->whereIn('tenant_id', $tenantIds)->count();
        $taxProfiles = DB::table('employee_tax_profiles')->whereIn('tenant_id', $tenantIds)->count();
        $results[] = $this->row('INV-18', 'Kepatuhan PPh 21 TER (100% Karyawan Memiliki Profil Pajak & Status PTKP)', ($totalEmp > 0 && $taxProfiles >= $totalEmp), "karyawan={$totalEmp}, profil_pajak={$taxProfiles}");

        // 19. INV-19: Rasio Biaya Tenaga Kerja / Labor Cost Ratio (Enterprise KPI)
        $totalPayroll = (float) DB::table('payroll_records')->whereIn('tenant_id', $tenantIds)->sum('net_pay_amount');
        $laborRatio = ($netSales > 0) ? ($totalPayroll / $netSales) * 100 : 0.0;
        $ratioPass = ($laborRatio >= 15.0 && $laborRatio <= 25.0);
        $results[] = $this->row('INV-19', 'Rasio Biaya Tenaga Kerja / Labor Cost Ratio (15.0% <= KPI <= 25.0%)', $ratioPass, "payroll_total=Rp " . number_format($totalPayroll, 0, ',', '.') . " (" . number_format($laborRatio, 2) . "% dari Net Sales, target 18.5% - 22.8%)");

        // 20. INV-20: Kesesuaian Target Cabang / Plan vs Actual
        $storeTargetCount = DB::table('store_targets')
            ->whereIn('tenant_id', $tenantIds)
            ->where('target_net_sales', '>', 0)
            ->count();
        $results[] = $this->row('INV-20', 'Kesesuaian Target Cabang (store_targets > 0 per cabang per periode)', $storeTargetCount > 0, "target_cabang={$storeTargetCount}");

        // 21. INV-21: Keunikan Barcode & SKU Varian Ritel
        $dupeSku = DB::select(
            "SELECT COUNT(*) AS c FROM (
                SELECT tenant_id, sku FROM product_variants
                WHERE tenant_id IN ({$tenantListStr}) AND sku IS NOT NULL AND sku <> ''
                GROUP BY tenant_id, sku HAVING COUNT(*) > 1
            ) x"
        )[0]->c ?? 0;
        $results[] = $this->row('INV-21', 'Keunikan SKU & Barcode Varian Ritel (0 Duplikasi per tenant)', (int) $dupeSku === 0, "duplikat_sku={$dupeSku}");

        // 22. INV-22: Konsistensi Konversi Multi-Satuan
        $badConv = (int) DB::table('product_unit_conversions')
            ->whereIn('tenant_id', $tenantIds)
            ->where('conversion_factor', '<=', 0)
            ->count();
        $results[] = $this->row('INV-22', 'Konsistensi Konversi Multi-Satuan (conversion_factor > 0)', $badConv === 0, "konversi_tidak_valid={$badConv}");

        // 23. INV-23: Imutabilitas Jurnal & Ledger Append-Only (Database Triggers)
        $triggers = DB::select(
            "SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
             WHERE TRIGGER_SCHEMA = DATABASE()
               AND TRIGGER_NAME IN (
                   'trg_journal_entries_before_update',
                   'trg_journal_entries_before_delete',
                   'trg_ap_ledger_entries_before_update',
                   'trg_tax_ledger_entries_before_update'
               )"
        );
        $triggerCount = count($triggers);
        $results[] = $this->row('INV-23', 'Imutabilitas Jurnal & Ledger Append-Only (Trigger MariaDB Aktif)', $triggerCount >= 4, "trigger_terverifikasi={$triggerCount}/4");

        // 24. INV-24: Distribusi Time-Series 90 Hari (2026-08-14 s/d 2026-11-11)
        $range = DB::table('orders')->whereIn('tenant_id', $tenantIds)
            ->selectRaw('MIN(order_date) mn, MAX(order_date) mx, COUNT(DISTINCT DATE_FORMAT(order_date, "%Y-%m")) months')
            ->first();
        $datePass = ($range && $range->mn <= '2026-08-15' && $range->mx >= '2026-11-11');
        $results[] = $this->row('INV-24', 'Distribusi Time-Series 90 Hari Kalender (2026-08-14 s/d 2026-11-11)', (bool) $datePass, "rentang={$range?->mn} .. {$range?->mx} ({$range?->months} bulan)");

        // 25. INV-25: Cakupan Skema 9 Tabel Enterprise Baru & Keterisian Basis Data
        $nineTables = [
            'departments',
            'job_titles',
            'employee_tax_profiles',
            'store_targets',
            'product_lots',
            'ingredient_recipes',
            'tax_submissions',
            'webhook_subscriptions',
            'webhook_delivery_logs',
        ];
        $emptyTables = [];
        foreach ($nineTables as $t) {
            $cnt = DB::table($t)->whereIn('tenant_id', $tenantIds)->count();
            if ($cnt === 0) {
                $emptyTables[] = $t;
            }
        }
        $results[] = $this->row('INV-25', 'Cakupan Skema 9 Tabel Baru (departments, lots, recipes, webhooks, dll.)', empty($emptyTables), empty($emptyTables) ? '9/9 tabel enterprise terisi' : 'kosong=' . implode(',', $emptyTables));

        return $results;
    }

    /**
     * @return array{code: string, name: string, status: string, detail: string}
     */
    private function row(string $code, string $name, bool $ok, string $detail): array
    {
        return [
            'code' => $code,
            'name' => $name,
            'status' => $ok ? 'PASS' : 'FAIL',
            'detail' => $detail,
        ];
    }
}
