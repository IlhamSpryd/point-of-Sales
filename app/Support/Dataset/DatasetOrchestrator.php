<?php

declare(strict_types=1);

namespace App\Support\Dataset;

use App\Support\Dataset\Generators\CatalogGenerator;
use App\Support\Dataset\Generators\DerivativeGenerator;
use App\Support\Dataset\Generators\Ekstensi\EkstensiCatalogGenerator;
use App\Support\Dataset\Generators\Ekstensi\EkstensiInventoryGenerator;
use App\Support\Dataset\Generators\Ekstensi\EkstensiOperationsGenerator;
use App\Support\Dataset\Generators\Ekstensi\EkstensiPlatformGenerator;
use App\Support\Dataset\Generators\Ekstensi\EkstensiProcurementGenerator;
use App\Support\Dataset\Generators\InventoryProcurementGenerator;
use App\Support\Dataset\Generators\SalesGenerator;
use App\Support\Dataset\Generators\SupplierInvoiceGenerator;
use App\Support\Dataset\Generators\TenantFoundationGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrator dataset demo: satu entry point untuk seluruh fase.
 *
 * Urutan dependency terbukti:
 *   tenant → subscription → roles → stores → users → devices → settings
 *     → katalog (kategori/bahan/produk/BOM/modifier/meja/pelanggan/pemasok)
 *     → stok awal → penjualan harian (+shift+payment+movement+loyalty)
 *     → pembelian (PO/GR) per bulan → retur/refund → EOD settlement
 *
 * Tidak ada TRUNCATE, tidak melepas FK/trigger, tidak ada hardcode ID.
 * Progress & checkpoint per hari agar bisa di-resume.
 */
final class DatasetOrchestrator
{
    public function __construct(
        private readonly DatasetConfig $config,
        private readonly ?Command $console = null,
    ) {}

    /**
     * @return array<string,mixed> laporan eksekusi (tanpa secret)
     */
    public function run(): array
    {
        DB::disableQueryLog();
        $startedAt = microtime(true);
        $writer = new DatasetWriter($this->config->batchSize, $this->console);
        $checkpoint = new DatasetCheckpoint;

        $reports = [];

        foreach ($this->config->accounts() as $account) {
            // Generator di-instantiate ulang per akun: state internal (id map,
            // daftar produk, dsb.) milik tenant sebelumnya tidak boleh bocor
            // ke akun berikutnya (mis. FK products milik tenant lain).
            $foundation = new TenantFoundationGenerator($this->config, $writer);
            $catalog = new CatalogGenerator($this->config, $writer);
            $inventory = new InventoryProcurementGenerator($this->config, $writer);
            $sales = new SalesGenerator($this->config, $writer);
            $derivatives = new DerivativeGenerator($this->config, $writer);
            $supplierInvoices = new SupplierInvoiceGenerator($this->config, $writer);
            $extCatalog = new EkstensiCatalogGenerator($this->config, $writer);
            $extProcurement = new EkstensiProcurementGenerator($this->config, $writer);
            $extInventory = new EkstensiInventoryGenerator($this->config, $writer);
            $extOperations = new EkstensiOperationsGenerator($this->config, $writer);
            $extPlatform = new EkstensiPlatformGenerator($this->config, $writer);
            $email = (string) $account['email'];
            $this->info('');
            $this->info("══════ AKUN DEMO: {$email} ══════");
            $accountStarted = microtime(true);

            // ── Fondasi ──────────────────────────────────────────────
            $this->info('[1/8] Fondasi tenant, cabang, akun, perangkat…');
            $ctx = $foundation->generate($account);
            $tenantId = (int) $ctx['tenant_id'];
            $writer->flushAll();

            $this->info(sprintf(
                '      tenant=%d cabang=%d pegawai=%d perangkat=%d',
                $tenantId, count($ctx['stores']), count($ctx['staff']), count($ctx['devices'])
            ));

            // ── Katalog ──────────────────────────────────────────────
            $this->info('[2/8] Katalog, BOM, modifier, pelanggan, pemasok…');
            $catalogData = $catalog->generate($tenantId, $ctx['stores']);
            $writer->flushAll();
            $ctx['products'] = $catalogData['products'];
            $ctx['customers'] = $catalogData['customers'];
            $ctx['discounts'] = $catalogData['discounts'];

            $this->info(sprintf(
                '      produk=%d pelanggan=%d pemasok=%d',
                count($catalogData['products']), count($catalogData['customers']), count($catalogData['suppliers'])
            ));

            // Katalog lanjutan: brand, UOM, atribut, varian, bundle,
            // price list, media, assortment, mapping channel.
            $extCatalog->generate($tenantId);
            $writer->flushAll();

            // ── Stok awal & pembelian ────────────────────────────────
            $this->info('[3/8] Stok awal & pengadaan…');
            $inventory->seedInitialStock($tenantId, $ctx['stores'], $catalogData['ingredients']);

            $supplierIds = [];
            foreach ($catalogData['suppliers'] as $code => $supplier) {
                $supplierIds[$code] = (int) (is_object($supplier) ? $supplier->id : $supplier['id']);
            }

            $approver = $ctx['staff'][0]['id'] ?? $ctx['owners'][0];
            $start = $this->config->startDate;
            $end = $this->config->effectiveEndDate();
            $poTotal = 0;

            $monthCursor = $start->startOfMonth();
            while ($monthCursor->lessThanOrEqualTo($end)) {
                $monthEnd = $monthCursor->endOfMonth()->min($end);
                $poTotal += $inventory->seedProcurement(
                    $tenantId, $ctx['stores'], $catalogData['ingredients'],
                    $supplierIds, (int) $approver, $monthCursor, $monthEnd
                );
                $monthCursor = $monthCursor->addMonthNoOverflow()->startOfMonth();
            }
            $this->info("      PO dibuat: {$poTotal}");

            // ── Penjualan harian ─────────────────────────────────────
            $this->info('[4/8] Transaksi penjualan 12 bulan (per hari)…');
            $sales->prepare($tenantId, $ctx, $catalogData['ingredients']);

            $resumeFrom = $this->config->resume
                ? $checkpoint->completedDay($tenantId, $this->config->runId)
                : null;

            // Checkpoint ditulis setelah hari selesai; resume mengulang hari
            // tersebut karena generator per-hari bersifat idempoten (marker).
            $day = $resumeFrom ?: $start;
            $totalDays = 0;
            $dayMetrics = ['orders' => 0, 'items' => 0, 'payments' => 0];
            $progressEvery = max(1, (int) floor($this->config->totalDays() / 24));
            $counter = 0;

            while ($day->lessThanOrEqualTo($end)) {
                $dayOrders = 0;
                DB::transaction(function () use ($ctx, $tenantId, $day, &$dayMetrics, &$dayOrders, $sales, $derivatives) {
                    foreach ($ctx['stores'] as $store) {
                        $metrics = $sales->generateDay($tenantId, $store, $ctx, $day);
                        foreach (['orders', 'items', 'payments'] as $k) {
                            $dayMetrics[$k] += $metrics[$k];
                        }
                        $dayOrders += $metrics['orders'];
                    }

                    // Retur untuk order yang cukup lama, dijalankan per minggu.
                    if ($day->dayOfWeek === 1) {
                        $derivatives->generateReturns($tenantId, $day->subDays(21), $day->subDays(7));
                    }
                });

                if ($counter % $this->config->daysPerCheckpoint === 0) {
                    $checkpoint->markDay($tenantId, $this->config->runId, $day, $dayMetrics);
                }

                $totalDays++;
                $counter++;
                if ($counter % $progressEvery === 0) {
                    $this->info(sprintf(
                        '      %s — %d hari, %s order',
                        $day->toDateString(), $counter, number_format($dayMetrics['orders'])
                    ));
                }

                $day = $day->addDay();
            }

            // ── Data turunan ─────────────────────────────────────────
            $this->info('[5/8] Retur, loyalty account, EOD settlement…');
            $adminId = (int) ($ctx['staff'][0]['id'] ?? $ctx['owners'][0]);
            $derivatives->generateReturns($tenantId, $end->subDays(30), $end);
            $loyaltyAccounts = $derivatives->syncLoyaltyAccounts($tenantId);
            $reconcile = $derivatives->reconcileStockBalances($tenantId);
            $eod = $derivatives->generateEodSettlements($tenantId, $ctx['stores'], $start, $end, (int) ($ctx['staff'][0]['id'] ?? $ctx['owners'][0]));

            // ── Faktur pemasok + AP (3-way match, pembayaran) ────────
            $this->info('[6/8] Faktur pemasok & AP…');
            $ap = $supplierInvoices->generate($tenantId, (int) ($ctx['staff'][0]['id'] ?? $ctx['owners'][0]), $start, $end);
            $this->info(sprintf(
                '      faktur=%d lines=%d matches=%d pembayaran=%d',
                $ap['invoices'], $ap['lines'], $ap['matches'], $ap['payments']
            ));

            $writer->flushAll();

            // ── Ringkasan per akun ───────────────────────────────────
            // ── Pengadaan lanjutan & dokumen gudang ──────────────────
            $this->info('[7/8] PR/approval/revisi PO, landed cost, retur pembelian…');
            $extProcurement->generate($tenantId, $adminId);
            $writer->flushAll();

            $this->info('      adjustment, opname, transfer, dokumen penerimaan…');
            $extInventory->generate($tenantId, $adminId);
            $writer->flushAll();

            // ── Jejak operasional & platform ─────────────────────────
            $this->info('      activity log, struk, cart, attempt bayar, channel…');
            $extOperations->generate($tenantId, $adminId);
            $writer->flushAll();

            $this->info('      RBAC, undangan staf, onboarding, email outbox…');
            $extPlatform->generate($tenantId, $adminId);
            $writer->flushAll();

            $this->info('[8/8] Ringkasan akun…');
            $summary = $this->accountSummary($tenantId, $email, $start, $end);
            $summary['duration_seconds'] = round(microtime(true) - $accountStarted, 2);
            $summary['loyalty_accounts'] = $loyaltyAccounts;
            $summary['eod_settlements'] = $eod;
            $summary['stock_reconciliations'] = $reconcile['balances_reconciled'];
            $summary['stock_adjustments'] = $reconcile['adjustments'];
            $summary['supplier_invoices'] = $ap['invoices'];
            $summary['supplier_invoice_payments'] = $ap['payments'];
            $reports[] = $summary;

            $this->info(sprintf(
                '      selesai: %s order, %s item, durasi %.1f detik',
                number_format($summary['counts']['orders']),
                number_format($summary['counts']['order_items']),
                $summary['duration_seconds']
            ));
        }

        return [
            'run_id' => $this->config->runId,
            'fingerprint' => $this->config->fingerprint(),
            'seed' => $this->config->seed,
            'period' => [
                'start' => $this->config->startDate->toDateString(),
                'end' => $this->config->effectiveEndDate()->toDateString(),
                'days' => $this->config->totalDays(),
            ],
            'batch_size' => $this->config->batchSize,
            'duration_seconds' => round(microtime(true) - $startedAt, 2),
            'accounts' => $reports,
            'writer_counters' => $writer->counters(),
        ];
    }

    /** @return array<string,mixed> */
    private function accountSummary(int $tenantId, string $email, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $count = fn (string $table, array $where = []) => (int) DB::table($table)
            ->where('tenant_id', $tenantId)->where($where)->count();

        $orders = DB::table('orders')->where('tenant_id', $tenantId);
        $range = (clone $orders)->selectRaw('MIN(order_date) as min_d, MAX(order_date) as max_d')->first();

        $monthly = DB::table('orders')
            ->selectRaw('DATE_FORMAT(order_date, "%Y-%m") as ym, COUNT(*) as c, SUM(order_amount) as total')
            ->where('tenant_id', $tenantId)->where('order_status', 'paid')
            ->groupBy('ym')->orderBy('ym')->get()
            ->map(fn ($r) => ['month' => $r->ym, 'orders' => (int) $r->c, 'revenue' => (int) $r->total])
            ->all();

        $paymentMix = DB::table('payments')
            ->selectRaw('payment_method, COUNT(*) as c, SUM(amount) as total')
            ->where('tenant_id', $tenantId)->where('status', 'captured')
            ->groupBy('payment_method')->get()
            ->map(fn ($r) => ['method' => $r->payment_method, 'count' => (int) $r->c, 'amount' => (int) $r->total])
            ->all();

        return [
            'email' => $email,
            'tenant_id' => $tenantId,
            'counts' => [
                'stores' => $count('stores'),
                'users' => $count('users'),
                'roles' => $count('roles'),
                'devices' => $count('devices'),
                'categories' => $count('categories'),
                'products' => $count('products'),
                'product_variants' => 0,
                'product_ingredients' => $count('product_ingredients'),
                'ingredients' => $count('ingredients'),
                'customers' => $count('customers'),
                'suppliers' => $count('suppliers'),
                'tables' => $count('tables'),
                'discounts' => $count('discounts'),
                'shifts' => $count('shifts'),
                'orders' => $count('orders'),
                'order_items' => (int) DB::table('order_items')
                    ->whereIn('order_id', DB::table('orders')->where('tenant_id', $tenantId)->select('id'))->count(),
                'order_item_modifiers' => (int) DB::table('order_item_modifiers')
                    ->whereIn('order_item_id', DB::table('order_items')
                        ->whereIn('order_id', DB::table('orders')->where('tenant_id', $tenantId)->select('id'))
                        ->select('id'))->count(),
                'payments' => $count('payments'),
                'order_returns' => $count('order_returns'),
                'payment_refunds' => $count('payment_refunds'),
                'purchase_orders' => $count('purchase_orders'),
                'goods_receipts' => $count('goods_receipts'),
                'stock_movements' => $count('stock_movements'),
                'ingredient_stock_movements' => $count('ingredient_stock_movements'),
                'loyalty_ledger' => $count('loyalty_ledger'),
                'customer_loyalty_accounts' => $count('customer_loyalty_accounts'),
                'cash_drawer_movements' => $count('cash_drawer_movements'),
                'eod_settlements' => $count('eod_settlements'),
            ],
            'date_range' => [
                'min' => $range->min_d ?? null,
                'max' => $range->max_d ?? null,
                'requested_start' => $start->toDateString(),
                'requested_end' => $end->toDateString(),
            ],
            'months_with_sales' => count($monthly),
            'monthly' => $monthly,
            'payment_mix' => $paymentMix,
        ];
    }

    private function info(string $message): void
    {
        $this->console?->info($message);
    }
}
