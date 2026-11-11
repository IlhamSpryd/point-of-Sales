<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Models\Order;
use App\Models\Shift;
use App\Models\User;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CashierWorkspaceService
 *
 * Ruang Kerja Kasir Transaksional (Frontline Cashier Shift Workspace).
 * Dirancang ringkas, cepat, dan terbebas dari beban analitik makro.
 * Menyajikan:
 * 1. Status shift aktif kasir (Nomor shift, jam mulai, durasi).
 * 2. Ringkasan kas personal (Kas awal, kas masuk, kas non-tunai QRIS/EDC).
 * 3. Peluncur cepat terminal POS ("Buka Terminal POS / Lanjutkan Transaksi").
 * 4. Riwayat 5 transaksi terakhir kasir bersangkutan (Reprint receipt / Void request).
 */
class CashierWorkspaceService
{
    public function __construct(
        protected TenantContext $tenantContext,
        protected MetricRegistry $metricRegistry,
    ) {}

    /**
     * Mengumpulkan ringkasan shift personal kasir yang sedang login.
     *
     * @return array<string, mixed>
     */
    public function getWorkspaceData(User $user): array
    {
        $tenantId = $this->tenantContext->requireTenantId();
        $storeId = (int) ($user->store_id ?? 1);

        // Cari shift aktif kasir yang bersangkutan
        $activeShift = DB::table('shifts')
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('user_id', $user->id)
            ->whereNull('closed_at')
            ->latest('opened_at')
            ->first();

        if (! $activeShift) {
            return [
                'has_active_shift' => false,
                'user_name' => $user->name,
                'store_id' => $storeId,
            ];
        }

        $now = Carbon::now('Asia/Jakarta');
        $openedAt = Carbon::parse($activeShift->opened_at ?? $activeShift->created_at);
        $durationHours = $openedAt->diffInHours($now);
        $durationMinutes = $openedAt->diffInMinutes($now) % 60;

        $startingCash = $this->metricRegistry->toNumericString($activeShift->opening_balance ?? $activeShift->starting_cash ?? '0', 2);

        // Agregasi transaksi kasir pada shift ini
        $shiftOrders = DB::table('orders')
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('shift_id', $activeShift->id)
            ->whereIn('order_status', ['paid', 'completed'])
            ->selectRaw('
                COUNT(id) as total_tx,
                COALESCE(SUM(order_amount), 0) as total_revenue
            ')
            ->first();

        $totalTx = (int) ($shiftOrders->total_tx ?? 0);
        $totalRevenue = $this->metricRegistry->toNumericString($shiftOrders->total_revenue ?? '0', 2);

        // Breakdown metode pembayaran pada shift ini
        $methodCol = Schema::hasColumn('payments', 'payment_method') ? 'payment_method' : 'payment_type';
        $paymentBreakdown = DB::table('payments as p')
            ->join('orders as o', 'p.order_id', '=', 'o.id')
            ->where('o.tenant_id', $tenantId)
            ->where('o.shift_id', $activeShift->id)
            ->where('p.status', 'success')
            ->selectRaw("
                p.{$methodCol} as method,
                COALESCE(SUM(p.amount), 0) as total_amount
            ")
            ->groupBy("p.{$methodCol}")
            ->pluck('total_amount', 'method')
            ->toArray();

        $cashSales = $this->metricRegistry->toNumericString($paymentBreakdown['cash'] ?? '0', 2);
        $qrisSales = $this->metricRegistry->toNumericString($paymentBreakdown['qris'] ?? '0', 2);
        $cardSales = $this->metricRegistry->toNumericString($paymentBreakdown['credit_card'] ?? ($paymentBreakdown['debit_card'] ?? ($paymentBreakdown['card'] ?? '0')), 2);
        $ewalletSales = $this->metricRegistry->toNumericString($paymentBreakdown['gopay'] ?? ($paymentBreakdown['shopeepay'] ?? ($paymentBreakdown['ewallet'] ?? '0')), 2);

        $nonCashSales = bcadd(bcadd($qrisSales, $cardSales, 2), $ewalletSales, 2);

        // Perhitungan uang fisik laci kasir yang diharapkan:
        // Expected = Starting Float + Cash Sales + Cash In - Cash Out - Cash Refunds
        $cashIn = $this->metricRegistry->toNumericString($activeShift->cash_in ?? '0', 2);
        $cashOut = $this->metricRegistry->toNumericString($activeShift->cash_out ?? '0', 2);
        $cashRefunds = $this->metricRegistry->toNumericString($activeShift->cash_refunds ?? '0', 2);

        $expectedDrawerCash = bcsub(
            bcsub(bcadd(bcadd($startingCash, $cashSales, 2), $cashIn, 2), $cashOut, 2),
            $cashRefunds,
            2
        );

        // 5 Transaksi terakhir kasir pada shift ini
        $codeCol = Schema::hasColumn('orders', 'order_code') ? 'order_code' : (Schema::hasColumn('orders', 'order_number') ? 'order_number' : 'id');
        $recentTransactions = DB::table('orders')
            ->where('tenant_id', $tenantId)
            ->where('shift_id', $activeShift->id)
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get()
            ->map(function ($o) use ($codeCol) {
                return [
                    'id' => $o->id,
                    'invoice_no' => $o->{$codeCol} ?? "ORD-{$o->id}",
                    'order_number' => $o->{$codeCol} ?? "ORD-{$o->id}",
                    'amount' => (float) ($o->order_amount ?? 0),
                    'amount_formatted' => 'Rp '.number_format((float) ($o->order_amount ?? 0), 0, ',', '.'),
                    'payment_method' => strtoupper((string) ($o->payment_method ?? 'CASH')),
                    'status' => $o->order_status,
                    'time' => Carbon::parse($o->created_at)->format('H:i'),
                ];
            })
            ->toArray();

        $storeName = DB::table('stores')->where('id', $storeId)->value('name') ?? 'Front Register';

        return [
            'has_active_shift' => true,
            'cashier_name' => $user->name,
            'user_name' => $user->name,
            'active_store_name' => $storeName,
            'active_shift' => [
                'id' => $activeShift->id,
                'start_time' => $openedAt->format('H:i'),
                'opened_at_formatted' => $openedAt->format('H:i'),
                'duration_label' => "{$durationHours} jam {$durationMinutes} menit",
                'starting_cash' => (float) $startingCash,
                'starting_cash_formatted' => 'Rp '.number_format((float) $startingCash, 0, ',', '.'),
            ],
            'shift' => [
                'id' => $activeShift->id,
                'opened_at_formatted' => $openedAt->format('H:i'),
                'duration_label' => "{$durationHours} jam {$durationMinutes} menit",
                'starting_cash' => $startingCash,
                'starting_cash_formatted' => 'Rp '.number_format((float) $startingCash, 0, ',', '.'),
                'expected_cash' => $expectedDrawerCash,
                'expected_cash_formatted' => 'Rp '.number_format((float) $expectedDrawerCash, 0, ',', '.'),
            ],
            'shift_expected_cash' => (float) $expectedDrawerCash,
            'my_today_sales' => (float) $totalRevenue,
            'my_today_tx_count' => $totalTx,
            'sales' => [
                'total_orders' => $totalTx,
                'total_revenue' => $totalRevenue,
                'total_revenue_formatted' => 'Rp '.number_format((float) $totalRevenue, 0, ',', '.'),
                'cash_sales' => $cashSales,
                'cash_sales_formatted' => 'Rp '.number_format((float) $cashSales, 0, ',', '.'),
                'non_cash_sales' => $nonCashSales,
                'non_cash_sales_formatted' => 'Rp '.number_format((float) $nonCashSales, 0, ',', '.'),
                'qris_sales_formatted' => 'Rp '.number_format((float) $qrisSales, 0, ',', '.'),
            ],
            'payment_breakdown' => [
                'cash' => (float) $cashSales,
                'qris' => (float) $qrisSales,
                'card' => (float) $cardSales,
                'ewallet' => (float) $ewalletSales,
            ],
            'recent_orders' => $recentTransactions,
        ];
    }
}
