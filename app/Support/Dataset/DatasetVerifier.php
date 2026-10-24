<?php

declare(strict_types=1);

namespace App\Support\Dataset;

use Illuminate\Support\Facades\DB;

/**
 * Verifikasi invariant dataset. Semua query READ-ONLY.
 *
 * Setiap check mengembalikan status PASS/FAIL dengan angka aktual sehingga
 * laporan akhir tidak bergantung pada klaim.
 */
final class DatasetVerifier
{
    /**
     * @param  array<int,int>  $tenantIds
     * @return array<int, array{name:string,status:string,detail:string}>
     */
    public function verify(array $tenantIds): array
    {
        $checks = [];

        // 1. Isolasi tenant: tidak ada order lintas tenant/store.
        $crossTenant = (int) DB::table('orders as o')
            ->join('stores as s', 's.id', '=', 'o.store_id')
            ->whereIn('o.tenant_id', $tenantIds)
            ->whereColumn('o.tenant_id', '!=', 's.tenant_id')
            ->count();
        $checks[] = $this->check('Isolasi order→store (tenant sama)', $crossTenant === 0, "pelanggaran={$crossTenant}");

        // 2. Setiap akun memiliki >= 2 cabang.
        foreach ($tenantIds as $tenantId) {
            $stores = (int) DB::table('stores')->where('tenant_id', $tenantId)->whereNull('deleted_at')->count();
            $checks[] = $this->check("Cabang tenant #{$tenantId}", $stores >= 2, "stores={$stores}");
        }

        // 3. Header order == subtotal + tax - discount (rekonsiliasi).
        $mismatch = (int) DB::table('orders')
            ->whereIn('tenant_id', $tenantIds)
            ->whereRaw('order_amount != (subtotal_amount - discount_amount + tax_amount + service_charge_amount)')
            ->count();
        $checks[] = $this->check('Rekonsiliasi total order', $mismatch === 0, "selisih={$mismatch}");

        // 4. Subtotal order == Σ order_items.order_subtotal.
        $itemMismatch = DB::select(
            'SELECT COUNT(*) AS c FROM (
                SELECT o.id, o.subtotal_amount, COALESCE(SUM(oi.order_subtotal),0) AS items_total
                FROM orders o LEFT JOIN order_items oi ON oi.order_id = o.id
                WHERE o.tenant_id IN ('.implode(',', array_map('intval', $tenantIds)).')
                GROUP BY o.id, o.subtotal_amount
                HAVING o.subtotal_amount <> items_total
            ) x'
        )[0]->c ?? 0;
        $checks[] = $this->check('Subtotal header == Σ item', (int) $itemMismatch === 0, "selisih={$itemMismatch}");

        // 5. Pembayaran lunas == order_amount (hanya order paid).
        $paymentMismatch = DB::select(
            'SELECT COUNT(*) AS c FROM (
                SELECT o.id, o.order_amount, COALESCE(SUM(p.amount),0) AS paid
                FROM orders o LEFT JOIN payments p ON p.order_id = o.id AND p.status = \'captured\'
                WHERE o.tenant_id IN ('.implode(',', array_map('intval', $tenantIds)).")
                  AND o.order_status = 'paid'
                GROUP BY o.id, o.order_amount
                HAVING o.order_amount <> paid
            ) x"
        )[0]->c ?? 0;
        $checks[] = $this->check('Pembayaran == total tagihan', (int) $paymentMismatch === 0, "selisih={$paymentMismatch}");

        // 6. Overpayment (trigger juga menjaga, ini verifikasi independen).
        $overpay = DB::select(
            'SELECT COUNT(*) AS c FROM (
                SELECT o.id FROM orders o
                JOIN payments p ON p.order_id = o.id AND p.status = \'captured\'
                WHERE o.tenant_id IN ('.implode(',', array_map('intval', $tenantIds)).')
                GROUP BY o.id, o.order_amount
                HAVING SUM(p.amount) > o.order_amount
            ) x'
        )[0]->c ?? 0;
        $checks[] = $this->check('Tidak ada overpayment', (int) $overpay === 0, "pelanggaran={$overpay}");

        // 7. FK valid: order_items → orders.
        $orphanItems = (int) DB::table('order_items')
            ->leftJoin('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNull('orders.id')->count();
        $checks[] = $this->check('FK order_items→orders', $orphanItems === 0, "orphan={$orphanItems}");

        // 8. Idempotensi marker: tidak ada duplikat idempotency_key order.
        $dupe = DB::select(
            'SELECT COUNT(*) AS c FROM (
                SELECT idempotency_key FROM orders
                WHERE tenant_id IN ('.implode(',', array_map('intval', $tenantIds)).')
                GROUP BY idempotency_key HAVING COUNT(*) > 1
            ) x'
        )[0]->c ?? 0;
        $checks[] = $this->check('Idempotency key unik', (int) $dupe === 0, "duplikat={$dupe}");

        // 9. Rentang tanggal 12 bulan.
        $range = DB::table('orders')->whereIn('tenant_id', $tenantIds)
            ->selectRaw('MIN(order_date) mn, MAX(order_date) mx, COUNT(DISTINCT DATE_FORMAT(order_date, "%Y-%m")) months')
            ->first();
        $checks[] = $this->check(
            'Distribusi bulan transaksi',
            (int) ($range->months ?? 0) >= 12,
            "bulan={$range->months} ({$range->mn} .. {$range->mx})"
        );

        // 10. Stok tidak negatif.
        $negIng = (int) DB::table('ingredient_stock_balances')->whereIn('tenant_id', $tenantIds)
            ->where('quantity', '<', 0)->count();
        $negProd = (int) DB::table('product_stock_balances')->whereIn('tenant_id', $tenantIds)
            ->where('quantity', '<', 0)->count();
        $checks[] = $this->check('Saldo stok non-negatif', $negIng === 0 && $negProd === 0, "ingredient={$negIng}, product={$negProd}");

        // 11. Rekonsiliasi saldo ingredient = Σ movement.
        $unreconciled = DB::select(
            'SELECT COUNT(*) AS c FROM ingredient_stock_balances b
             LEFT JOIN (
                SELECT tenant_id, store_id, ingredient_id, SUM(quantity) q
                FROM ingredient_stock_movements
                WHERE tenant_id IN ('.implode(',', array_map('intval', $tenantIds)).')
                GROUP BY tenant_id, store_id, ingredient_id
             ) m ON m.tenant_id=b.tenant_id AND m.store_id=b.store_id AND m.ingredient_id=b.ingredient_id
             WHERE b.tenant_id IN ('.implode(',', array_map('intval', $tenantIds)).')
               AND ABS(COALESCE(m.q,0) - b.quantity) > 0.01'
        )[0]->c ?? 0;
        $checks[] = $this->check('Saldo ingredient == Σ ledger', (int) $unreconciled === 0, "selisih={$unreconciled}");

        // 12. Shift: satu shift aktif per kasir per cabang.
        $dupShift = DB::select(
            'SELECT COUNT(*) AS c FROM (
                SELECT tenant_id, store_id, active_cashier_id FROM shifts
                WHERE tenant_id IN ('.implode(',', array_map('intval', $tenantIds)).")
                AND status IN ('open','handover_pending','closing') AND active_cashier_id IS NOT NULL
                GROUP BY tenant_id, store_id, active_cashier_id HAVING COUNT(*) > 1
            ) x"
        )[0]->c ?? 0;
        $checks[] = $this->check('Shift aktif unik per kasir', (int) $dupShift === 0, "duplikat={$dupShift}");

        return $checks;
    }

    /** @return array{name:string,status:string,detail:string} */
    private function check(string $name, bool $ok, string $detail): array
    {
        return ['name' => $name, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
    }
}
