<?php

declare(strict_types=1);

namespace App\Support\Dataset\Generators\Ekstensi;

use App\Support\Dataset\DatasetConfig;
use App\Support\Dataset\DatasetWriter;
use App\Support\Dataset\Generators\Ekstensi\Concerns\ResolvesDatasetContext;
use App\Support\Dataset\SeededRandom;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Jejak operasional harian yang belum dibuat generator inti:
 *
 *   activity_logs (login, buka/tutup shift, void, refund, edit harga, dsb.)
 *   receipt_print_logs (cetak ulang struk)
 *   payment_attempts (riwayat percobaan pembayaran non-tunai)
 *   discount_redemptions (pemakaian kode promo)
 *   parked_carts (pesanan ditahan lalu dilanjutkan/dibuang)
 *   shift_handovers (serah terima kasir di tengah shift)
 *   channel_order_logs (pesanan dari aggregator — termasuk yang gagal)
 *   export_tasks (unduhan laporan)
 */
final class EkstensiOperationsGenerator
{
    use ResolvesDatasetContext;

    public function __construct(
        private readonly DatasetConfig $config,
        private readonly DatasetWriter $writer,
    ) {}

    public function generate(int $tenantId, int $ownerId): void
    {
        $this->ensureActivityLogs($tenantId, $ownerId);
        $this->ensureReceiptPrints($tenantId);
        $this->ensurePaymentAttempts($tenantId);
        $this->ensureDiscountRedemptions($tenantId);
        $this->ensureParkedCarts($tenantId);
        $this->ensureShiftHandovers($tenantId);
        $this->ensureChannelOrderLogs($tenantId);
        $this->ensureExportTasks($tenantId);
    }

    private function ensureActivityLogs(int $tenantId, int $ownerId): void
    {
        if ($this->tenantHasRows('activity_logs', $tenantId)) {
            return;
        }

        $rng = $this->rngFor($tenantId, 'activity');
        $start = $this->config->startDate;
        $end = $this->config->effectiveEndDate();
        $userAgents = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0 Safari/537.36',
            'ApeironPOS/2.4 (Android 14; POS Terminal)',
            'ApeironPOS/2.4 (iPadOS 17; WebView)',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) Safari/17.5',
        ];

        $shifts = DB::table('shifts')
            ->where('tenant_id', $tenantId)
            ->whereBetween('opened_at', [$start->toDateTimeString(), $end->addDay()->toDateTimeString()])
            ->orderBy('opened_at')
            ->get(['id', 'store_id', 'user_id', 'device_id', 'opened_at', 'closed_at']);

        /** @var list<array<string, mixed>> $rows */
        $rows = [];
        $bufferLimit = 2000;

        $flush = function () use (&$rows): void {
            if ($rows === []) {
                return;
            }
            foreach (array_chunk($rows, $this->writer->batchSize()) as $chunk) {
                DB::table('activity_logs')->insert($chunk);
                $this->writer->markWritten('activity_logs', count($chunk));
            }
            $rows = [];
        };

        // Proses bertahap (chunk) agar memori & jumlah query terkendali untuk
        // dataset 12 bulan x 11 cabang (~7.000 shift).
        foreach ($shifts->chunk(500) as $shiftChunk) {
            foreach ($shiftChunk as $shift) {
                $openedAt = CarbonImmutable::parse($shift->opened_at);
                $closedAt = $shift->closed_at ? CarbonImmutable::parse($shift->closed_at) : null;

                $rows[] = $this->activityRow($tenantId, (int) $shift->store_id, (int) $shift->user_id, 'login', 'users',
                    (int) $shift->user_id, 'Login ke terminal POS', $openedAt->subMinutes(6), $rng, $userAgents);
                $rows[] = $this->activityRow($tenantId, (int) $shift->store_id, (int) $shift->user_id, 'shift.open', 'shifts',
                    (int) $shift->id, 'Membuka shift kasir', $openedAt, $rng, $userAgents);

                if ($closedAt) {
                    $rows[] = $this->activityRow($tenantId, (int) $shift->store_id, (int) $shift->user_id, 'shift.close', 'shifts',
                        (int) $shift->id, 'Menutup shift kasir', $closedAt, $rng, $userAgents);
                    $rows[] = $this->activityRow($tenantId, (int) $shift->store_id, (int) $shift->user_id, 'logout', 'users',
                        (int) $shift->user_id, 'Logout dari terminal POS', $closedAt->addMinutes(3), $rng, $userAgents);
                }

                if (count($rows) >= $bufferLimit) {
                    $flush();
                }
            }
            $flush();
        }

        // Aktivitas dari order: void, cetak ulang, refund.
        $orders = DB::table('orders')->where('tenant_id', $tenantId)
            ->where('order_status', 'void')
            ->whereBetween('order_date', [$start->toDateString(), $end->toDateString()])
            ->limit(800)->get(['id', 'store_id', 'user_id', 'order_code', 'voided_at']);

        foreach ($orders as $order) {
            $at = $order->voided_at ? CarbonImmutable::parse($order->voided_at) : $start;
            $rows[] = $this->activityRow($tenantId, (int) $order->store_id, (int) $order->user_id, 'order.void', 'orders',
                (int) $order->id, 'Membatalkan transaksi '.$order->order_code, $at, $rng, $userAgents, ['reason' => 'Salah input kasir']);
        }

        $returns = DB::table('order_returns')->where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$start->toDateTimeString(), $end->addDay()->toDateTimeString()])
            ->limit(600)->get(['id', 'store_id', 'order_id', 'return_number', 'requested_by_user_id', 'created_at']);

        foreach ($returns as $ret) {
            $rows[] = $this->activityRow($tenantId, (int) $ret->store_id, (int) $ret->requested_by_user_id, 'return.create', 'order_returns',
                (int) $ret->id, 'Membuat retur '.$ret->return_number, CarbonImmutable::parse($ret->created_at), $rng, $userAgents);
        }

        // Perubahan data master oleh manajer/owner.
        $managerId = $this->userWithRole($tenantId, 'ROL-MANAGER', $ownerId);
        $stores = DB::table('stores')->where('tenant_id', $tenantId)->whereNull('deleted_at')->get(['id']);

        foreach (['products', 'ingredients', 'discounts'] as $table) {
            foreach ($stores as $store) {
                for ($i = 0; $i < 6; $i++) {
                    $at = $this->randomDay($rng, $start, $end);
                    $rows[] = $this->activityRow($tenantId, (int) $store->id, $managerId, 'master.'.$table.'.update', $table,
                        $rng->int(1, 100), 'Memperbarui data '.$table, $at, $rng, $userAgents);
                }
            }
        }

        $flush();
    }

    /** @param array<int,string> $userAgents */
    private function activityRow(
        int $tenantId,
        int $storeId,
        ?int $userId,
        string $action,
        string $subjectType,
        int $subjectId,
        string $description,
        CarbonImmutable $at,
        SeededRandom $rng,
        array $userAgents,
        ?array $newValues = null,
    ): array {
        return [
            'tenant_id' => $tenantId,
            'store_id' => $storeId,
            'user_id' => $userId,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'description' => $description,
            'old_values' => null,
            'new_values' => $newValues ? json_encode($newValues) : null,
            'ip_address' => $rng->bool(0.5) ? '10.10.'.(int) $storeId.'.'.$rng->int(2, 250) : null,
            'user_agent' => $userAgents[$rng->int(0, count($userAgents) - 1)],
            'metadata' => json_encode(['source' => 'pos_terminal', 'store_id' => $storeId]),
            'created_at' => $at,
            'updated_at' => $at,
        ];
    }

    private function ensureReceiptPrints(int $tenantId): void
    {
        if ($this->tenantHasRows('receipt_print_logs', $tenantId)) {
            return;
        }

        $rng = $this->rngFor($tenantId, 'prints');
        $orders = DB::table('orders')->where('tenant_id', $tenantId)
            ->where('order_status', 'paid')
            ->orderBy('id')->limit(1500)
            ->get(['id', 'store_id', 'user_id', 'shift_id', 'created_by_device_id', 'created_at']);

        $rows = [];
        foreach ($orders as $order) {
            $at = CarbonImmutable::parse($order->created_at);
            $rows[] = [
                'tenant_id' => $tenantId,
                'store_id' => (int) $order->store_id,
                'order_id' => (int) $order->id,
                'device_id' => $order->created_by_device_id !== null ? (int) $order->created_by_device_id : null,
                'shift_id' => $order->shift_id !== null ? (int) $order->shift_id : null,
                'printed_by_user_id' => (int) $order->user_id,
                'print_sequence' => 1,
                'is_reprint' => 0,
                'reprint_reason' => null,
                'printed_at' => $at->addSeconds($rng->int(5, 40)),
                'created_at' => $at,
                'updated_at' => $at,
            ];

            if ($rng->bool(0.06)) {
                $rows[] = [
                    'tenant_id' => $tenantId,
                    'store_id' => (int) $order->store_id,
                    'order_id' => (int) $order->id,
                    'device_id' => $order->created_by_device_id !== null ? (int) $order->created_by_device_id : null,
                    'shift_id' => $order->shift_id !== null ? (int) $order->shift_id : null,
                    'printed_by_user_id' => (int) $order->user_id,
                    'print_sequence' => 2,
                    'is_reprint' => 1,
                    'reprint_reason' => $rng->pick(['Struk rusak', 'Permintaan pelanggan', 'Printer kehabisan kertas']),
                    'printed_at' => $at->addMinutes($rng->int(1, 10)),
                    'created_at' => $at,
                    'updated_at' => $at,
                ];
            }
        }

        foreach (array_chunk($rows, $this->writer->batchSize()) as $chunk) {
            DB::table('receipt_print_logs')->insert($chunk);
            $this->writer->markWritten('receipt_print_logs', count($chunk));
        }
    }

    /** Riwayat percobaan pembayaran non-tunai (termasuk yang gagal/timeout). */
    private function ensurePaymentAttempts(int $tenantId): void
    {
        if ($this->tenantHasRows('payment_attempts', $tenantId)) {
            return;
        }

        $rng = $this->rngFor($tenantId, 'payattempt');
        $payments = DB::table('payments')->where('tenant_id', $tenantId)
            ->whereIn('payment_method', ['qris', 'ewallet', 'card'])
            ->where('status', 'captured')
            ->orderBy('id')->limit(2000)
            ->get(['id', 'store_id', 'order_id', 'device_id', 'shift_id', 'payment_method', 'amount',
                'captured_at', 'processed_by', 'reference_number']);

        $rows = [];
        foreach ($payments as $payment) {
            $settledAt = $payment->captured_at ? CarbonImmutable::parse($payment->captured_at) : $this->config->startDate;
            $initiated = $settledAt->subSeconds($rng->int(20, 240));
            $provider = match ($payment->payment_method) {
                'qris' => 'midtrans_qris',
                'ewallet' => 'xendit_ewallet',
                default => 'stripe_card',
            };

            // Percobaan gagal lebih dulu pada sebagian transaksi.
            if ($rng->bool(0.09)) {
                $rows[] = [
                    'tenant_id' => $tenantId,
                    'store_id' => (int) $payment->store_id,
                    'order_id' => (int) $payment->order_id,
                    'payment_id' => null,
                    'device_id' => $payment->device_id !== null ? (int) $payment->device_id : null,
                    'shift_id' => $payment->shift_id !== null ? (int) $payment->shift_id : null,
                    'idempotency_key' => $this->config->marker("pa:fail:{$payment->id}"),
                    'provider' => $provider,
                    'payment_method' => $payment->payment_method,
                    'provider_order_id' => sprintf('%s-%d-FAIL', strtoupper(substr(md5((string) $payment->id), 0, 12)), $payment->id),
                    'provider_transaction_id' => null,
                    'amount' => (int) $payment->amount,
                    'currency' => 'IDR',
                    'status' => $rng->weighted(['failed' => 60, 'expired' => 25, 'cancelled' => 15]),
                    'raw_request_meta' => json_encode(['timeout_ms' => 30000, 'channel' => $payment->payment_method]),
                    'raw_response_meta' => json_encode(['error_code' => 'TIMEOUT', 'message' => 'Provider tidak merespons']),
                    'initiated_by_user_id' => $payment->processed_by !== null ? (int) $payment->processed_by : null,
                    'settled_at' => null,
                    'expired_at' => $initiated->addMinutes(15),
                    'created_at' => $initiated,
                    'updated_at' => $initiated->addMinutes(1),
                ];
            }

            $rows[] = [
                'tenant_id' => $tenantId,
                'store_id' => (int) $payment->store_id,
                'order_id' => (int) $payment->order_id,
                'payment_id' => (int) $payment->id,
                'device_id' => $payment->device_id !== null ? (int) $payment->device_id : null,
                'shift_id' => $payment->shift_id !== null ? (int) $payment->shift_id : null,
                'idempotency_key' => $this->config->marker("pa:ok:{$payment->id}"),
                'provider' => $provider,
                'payment_method' => $payment->payment_method,
                'provider_order_id' => sprintf('%s-%d-OK', strtoupper(substr(md5((string) $payment->id), 0, 12)), $payment->id),
                'provider_transaction_id' => $payment->reference_number ?: sprintf('TX-%s', strtoupper(substr(md5((string) $payment->id), 0, 16))),
                'amount' => (int) $payment->amount,
                'currency' => 'IDR',
                'status' => 'succeeded',
                'raw_request_meta' => json_encode(['timeout_ms' => 30000, 'channel' => $payment->payment_method]),
                'raw_response_meta' => json_encode(['status_code' => '200', 'transaction_status' => 'settlement']),
                'initiated_by_user_id' => $payment->processed_by !== null ? (int) $payment->processed_by : null,
                'settled_at' => $settledAt,
                'expired_at' => null,
                'created_at' => $initiated,
                'updated_at' => $settledAt,
            ];
        }

        foreach (array_chunk($rows, $this->writer->batchSize()) as $chunk) {
            DB::table('payment_attempts')->insert($chunk);
            $this->writer->markWritten('payment_attempts', count($chunk));
        }
    }

    private function ensureDiscountRedemptions(int $tenantId): void
    {
        if ($this->tenantHasRows('discount_redemptions', $tenantId)) {
            return;
        }

        $orders = DB::table('orders')->where('tenant_id', $tenantId)
            ->whereNotNull('discount_id')
            ->where('discount_amount', '>', 0)
            ->orderBy('id')->limit(4000)
            ->get(['id', 'store_id', 'user_id', 'customer_id', 'discount_id', 'discount_amount',
                'order_status', 'created_at']);

        if ($orders->isEmpty()) {
            return;
        }

        $codes = DB::table('discounts')->where('tenant_id', $tenantId)->pluck('code', 'id');
        $rows = [];

        foreach ($orders as $order) {
            $rows[] = [
                'tenant_id' => $tenantId,
                'store_id' => (int) $order->store_id,
                'discount_id' => (int) $order->discount_id,
                'order_id' => (int) $order->id,
                'customer_id' => $order->customer_id !== null ? (int) $order->customer_id : null,
                'redeemed_by_user_id' => (int) $order->user_id,
                'discount_code_snapshot' => $codes[$order->discount_id] ?? null,
                'amount_discounted' => (int) $order->discount_amount,
                'status' => $order->order_status === 'void' ? 'voided_reversed' : 'applied',
                'redeemed_at' => $order->created_at,
                'reversed_at' => $order->order_status === 'void' ? $order->created_at : null,
                'created_at' => $order->created_at,
                'updated_at' => $order->created_at,
            ];
        }

        foreach (array_chunk($rows, $this->writer->batchSize()) as $chunk) {
            DB::table('discount_redemptions')->insert($chunk);
            $this->writer->markWritten('discount_redemptions', count($chunk));
        }
    }

    /**
     * Parked cart: transaksi ditahan kasir lalu dilanjutkan (converted),
     * kedaluwarsa, atau dibuang.
     */
    private function ensureParkedCarts(int $tenantId): void
    {
        if ($this->tenantHasRows('parked_carts', $tenantId)) {
            return;
        }

        $rng = $this->rngFor($tenantId, 'parked');
        $shifts = DB::table('shifts')->where('tenant_id', $tenantId)
            ->whereNotNull('device_id')
            ->whereBetween('opened_at', [$this->config->startDate->toDateTimeString(), $this->config->effectiveEndDate()->endOfDay()->toDateTimeString()])
            ->orderBy('id')->limit(1200)
            ->get(['id', 'store_id', 'device_id', 'user_id', 'opened_at', 'closed_at']);

        if ($shifts->isEmpty()) {
            return;
        }

        $products = DB::table('products')->where('tenant_id', $tenantId)->whereNull('deleted_at')
            ->limit(20)->get(['id', 'product_name', 'product_price']);

        $rows = [];
        $seq = 0;
        foreach ($shifts as $shift) {
            if (! $rng->bool(0.18)) {
                continue;
            }

            $seq++;
            $parkedAt = CarbonImmutable::parse($shift->opened_at)->addMinutes($rng->int(30, 240));
            $status = $rng->weighted(['converted' => 55, 'parked' => 15, 'expired' => 20, 'discarded' => 10]);
            $order = DB::table('orders')->where('tenant_id', $tenantId)->where('store_id', $shift->store_id)
                ->where('shift_id', $shift->id)->orderBy('id')->first(['id']);

            $items = [];
            foreach ($rng->sample($products->all(), $rng->int(1, 3)) as $product) {
                $items[] = [
                    'product_id' => (int) $product->id,
                    'name' => $product->product_name,
                    'price' => (int) $product->product_price,
                    'qty' => $rng->int(1, 2),
                ];
            }

            $rows[] = [
                'tenant_id' => $tenantId,
                'store_id' => (int) $shift->store_id,
                'device_id' => (int) $shift->device_id,
                'shift_id' => (int) $shift->id,
                'parked_by_user_id' => (int) $shift->user_id,
                'customer_id' => null,
                'table_id' => null,
                'hold_reference' => sprintf('HOLD-%s-%03d', CarbonImmutable::parse($shift->opened_at)->format('ymd'), $seq),
                'customer_label' => $rng->pick(['Bapak putih', 'Ibu hijau', 'Meja dekat pintu', 'Ojek online']),
                'order_type' => $rng->weighted(['dine_in' => 60, 'takeaway' => 35, 'delivery' => 5]),
                'cart_payload' => json_encode(['items' => $items, 'note' => 'Ditahan sementara']),
                'discount_id' => null,
                'notes' => 'Ditahan menunggu pelanggan selesai',
                'status' => $status,
                'expires_at' => $parkedAt->addHours(4),
                'resumed_by_user_id' => in_array($status, ['converted'], true) ? (int) $shift->user_id : null,
                'converted_order_id' => $status === 'converted' && $order ? (int) $order->id : null,
                'created_at' => $parkedAt,
                'updated_at' => $parkedAt,
            ];
        }

        foreach (array_chunk($rows, $this->writer->batchSize()) as $chunk) {
            DB::table('parked_carts')->insert($chunk);
            $this->writer->markWritten('parked_carts', count($chunk));
        }
    }

    /**
     * Serah terima kasir: shift ditutup lebih awal lalu dilanjutkan kasir lain
     * pada perangkat yang sama.
     */
    private function ensureShiftHandovers(int $tenantId): void
    {
        if ($this->tenantHasRows('shift_handovers', $tenantId)) {
            return;
        }

        $rng = $this->rngFor($tenantId, 'handover');

        $shifts = DB::table('shifts')->where('tenant_id', $tenantId)
            ->whereNotNull('device_id')
            ->where('status', 'closed')
            ->orderBy('id')->limit(600)
            ->get(['id', 'store_id', 'device_id', 'user_id', 'opened_at', 'closed_at', 'expected_cash', 'closing_balance']);

        $cashiersByStore = $this->usersByStore($tenantId, 'ROL-KASIR', 0);
        $rows = [];

        foreach ($shifts as $shift) {
            if (! $rng->bool(0.08)) {
                continue;
            }

            // Kasir lain di cabang yang sama (relasi lewat user_stores).
            $toUser = (int) (DB::table('users as u')
                ->join('roles as r', 'r.id', '=', 'u.role_id')
                ->join('user_stores as us', 'us.user_id', '=', 'u.id')
                ->where('u.tenant_id', $tenantId)
                ->where('us.store_id', $shift->store_id)
                ->where('r.role_code', 'ROL-KASIR')
                ->where('u.id', '<>', $shift->user_id)
                ->orderBy('u.id')
                ->value('u.id') ?? 0);

            if ($toUser === 0) {
                $toUser = (int) ($cashiersByStore[$shift->store_id] ?? $shift->user_id);
            }
            if ($toUser === (int) $shift->user_id) {
                continue; // tanpa rekan kasir → tidak ada serah terima
            }

            $at = CarbonImmutable::parse($shift->opened_at)->addHours($rng->int(3, 6));
            $expected = (int) $shift->expected_cash;
            $counted = max(0, $expected + ($rng->weighted([0 => 70, 1 => 12, -1 => 10, 2 => 4, -2 => 4]) * 1000));

            $rows[] = [
                'tenant_id' => $tenantId,
                'store_id' => (int) $shift->store_id,
                'shift_id' => (int) $shift->id,
                'device_id' => (int) $shift->device_id,
                'from_user_id' => (int) $shift->user_id,
                'to_user_id' => $toUser,
                'approved_by_user_id' => $toUser,
                'expected_cash_at_handover' => $expected,
                'counted_cash_at_handover' => $counted,
                'discrepancy_amount' => $counted - $expected,
                'status' => 'accepted',
                'notes' => 'Serah terima kasir pergantian jam kerja',
                'initiated_at' => $at,
                'completed_at' => $at->addMinutes($rng->int(2, 18)),
                'created_at' => $at,
                'updated_at' => $at,
            ];
        }

        foreach (array_chunk($rows, $this->writer->batchSize()) as $chunk) {
            DB::table('shift_handovers')->insert($chunk);
            $this->writer->markWritten('shift_handovers', count($chunk));
        }
    }

    /** Log pesanan masuk dari channel (GoFood/GrabFood/ShopeeFood). */
    private function ensureChannelOrderLogs(int $tenantId): void
    {
        if ($this->tenantHasRows('channel_order_logs', $tenantId)) {
            return;
        }

        $rng = $this->rngFor($tenantId, 'channels');
        $orders = DB::table('orders')->where('tenant_id', $tenantId)
            ->where('order_type', 'delivery')
            ->orderBy('id')->limit(1200)
            ->get(['id', 'store_id', 'order_code', 'order_amount', 'created_at']);

        $providers = ['gofood' => 'GF', 'grabfood' => 'GRB', 'shopeefood' => 'SPF'];
        $rows = [];

        foreach ($orders as $order) {
            // weighted() mengembalikan indeks numerik; petakan ke kode provider.
            $providerKeys = array_keys($providers);
            $provider = $providerKeys[(int) $rng->weighted($providers)];
            $at = CarbonImmutable::parse($order->created_at);
            $rows[] = [
                'tenant_id' => $tenantId,
                'provider' => $provider,
                'external_order_id' => sprintf('%s-%s', $providers[$provider], substr(md5($order->order_code), 0, 12)),
                'status' => 'processed',
                'order_id' => (int) $order->id,
                'payload' => json_encode([
                    'order_code' => $order->order_code,
                    'store_id' => (int) $order->store_id,
                    'gross_amount' => (int) $order->order_amount,
                    'platform_fee' => (int) round($order->order_amount * 0.2),
                ]),
                'error_message' => null,
                'created_at' => $at,
                'updated_at' => $at->addMinutes(1),
            ];

            // Sebagian pesanan aggregator gagal diproses (payload tidak valid).
            if ($rng->bool(0.03)) {
                $rows[] = [
                    'tenant_id' => $tenantId,
                    'provider' => $provider,
                    'external_order_id' => sprintf('%s-%s-FAIL', $providers[$provider], strtoupper(substr(md5('f'.$order->order_code), 0, 11))),
                    'status' => 'failed',
                    'order_id' => null,
                    'payload' => json_encode(['order_code' => $order->order_code, 'items' => []]),
                    'error_message' => 'Produk tidak ditemukan pada mapping channel',
                    'created_at' => $at->addMinutes($rng->int(1, 5)),
                    'updated_at' => $at->addMinutes($rng->int(6, 10)),
                ];
            }
        }

        foreach (array_chunk($rows, $this->writer->batchSize()) as $chunk) {
            DB::table('channel_order_logs')->insert($chunk);
            $this->writer->markWritten('channel_order_logs', count($chunk));
        }
    }

    /** Riwayat unduhan laporan (export async). */
    private function ensureExportTasks(int $tenantId): void
    {
        if ($this->tenantHasRows('export_tasks', $tenantId)) {
            return;
        }

        $rng = $this->rngFor($tenantId, 'exports');
        $stores = DB::table('stores')->where('tenant_id', $tenantId)->whereNull('deleted_at')->get(['id']);
        $types = ['sales_daily' => 40, 'sales_monthly' => 25, 'inventory_snapshot' => 15, 'customer_list' => 10, 'purchase_history' => 10];

        $rows = [];
        $cursor = $this->monthStart($this->config->startDate);
        $end = $this->config->effectiveEndDate();

        while ($cursor->lessThanOrEqualTo($end)) {
            for ($i = 0; $i < 4; $i++) {
                $store = $stores[$rng->int(0, count($stores) - 1)];
                $type = $rng->weighted($types);
                $requestedAt = $cursor->addDays($rng->int(0, 27))->setTime($rng->int(8, 19), $rng->int(0, 59));
                if ($requestedAt->greaterThan($end)) {
                    continue;
                }
                $status = $rng->weighted(['completed' => 85, 'failed' => 7, 'processing' => 5, 'pending' => 3]);
                $fileName = sprintf('%s-%s-%s.csv', $type, $store->id, $requestedAt->format('Ymd'));

                $rows[] = [
                    'tenant_id' => $tenantId,
                    'store_id' => (int) $store->id,
                    'requested_by' => $this->userWithRole($tenantId, 'ROL-MANAGER', 0) ?: null,
                    'type' => $type,
                    'parameters' => json_encode([
                        'from' => $cursor->toDateString(),
                        'to' => $cursor->endOfMonth()->toDateString(),
                        'store_id' => (int) $store->id,
                    ]),
                    'status' => $status,
                    'file_path' => $status === 'completed' ? 'exports/'.$tenantId.'/'.$fileName : null,
                    'file_size_bytes' => $status === 'completed' ? $rng->int(20_000, 900_000) : null,
                    'error_message' => $status === 'failed' ? 'Timeout saat menyusun laporan' : null,
                    'completed_at' => $status === 'completed' ? $requestedAt->addMinutes($rng->int(1, 12)) : null,
                    'created_at' => $requestedAt,
                    'updated_at' => $requestedAt,
                ];
            }
            $cursor = $this->nextMonthStart($cursor);
        }

        foreach (array_chunk($rows, $this->writer->batchSize()) as $chunk) {
            DB::table('export_tasks')->insert($chunk);
            $this->writer->markWritten('export_tasks', count($chunk));
        }
    }
}
