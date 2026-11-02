<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ExportTaskStatus;
use App\Models\ExportTask;
use App\Modules\Reports\Services\Analytics\CustomerAnalyticsService;
use App\Modules\Reports\Services\Analytics\FinancialBiService;
use App\Modules\Reports\Services\Analytics\InventoryAnalyticsService;
use App\Modules\Reports\Services\Analytics\SalesAnalyticsService;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessAnalyticsExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 600;
    public int $backoff = 10;

    public int $tenantId;
    public ?int $storeId = null;

    public function __construct(
        public ExportTask $exportTask,
        ?int $tenantId = null,
        ?int $storeId = null
    ) {
        $this->tenantId = $tenantId ?? (int) $exportTask->tenant_id;
        $this->storeId = $storeId ?? $exportTask->store_id;
        $this->onQueue('exports');
    }

    public function handle(
        SalesAnalyticsService $salesService,
        InventoryAnalyticsService $inventoryService,
        FinancialBiService $financialService,
        CustomerAnalyticsService $customerService
    ): void {
        $tenantContext = app(TenantContext::class);
        $prevTenantId = $tenantContext->getTenantId();
        $prevStoreId = $tenantContext->getStoreId();

        $tenantContext->setTenantId($this->tenantId);
        if ($this->storeId !== null) {
            $tenantContext->setStoreId($this->storeId);
        }

        $this->exportTask->update(['status' => ExportTaskStatus::Processing]);

        try {
            $params = $this->exportTask->parameters ?? [];
            $startDate = isset($params['start_date']) ? Carbon::parse((string) $params['start_date']) : Carbon::now()->subDays(30);
            $endDate = isset($params['end_date']) ? Carbon::parse((string) $params['end_date']) : Carbon::now();
            $storeId = isset($params['store_id']) && $params['store_id'] !== '' ? (int) $params['store_id'] : $this->storeId;

            $csvData = [];
            $type = (string) $this->exportTask->type;

            switch ($type) {
                case 'sales_analytics':
                    $csvData[] = ['METRIK PENJUALAN', 'NILAI'];
                    $trend = $salesService->getSalesTrend($startDate, $endDate, 'daily', $storeId);
                    $csvData[] = ['Total Hari Dianalisis', count($trend)];
                    $topProducts = $salesService->getTopProducts($startDate, $endDate, $storeId, 20);
                    $csvData[] = [];
                    $csvData[] = ['SKU / KODE', 'NAMA PRODUK', 'KATEGORI', 'QTY TERJUAL', 'TOTAL PENDAPATAN', 'KONTRIBUSI %'];
                    foreach ($topProducts as $p) {
                        $csvData[] = [$p['sku'], $p['product_name'], $p['category_name'], $p['quantity_sold'], $p['total_revenue'], $p['contribution_pct']];
                    }
                    break;

                case 'inventory_analytics':
                    $aging = $inventoryService->getStockAging($storeId);
                    $csvData[] = ['ANALISIS UMUR STOK (AGING)', 'JUMLAH ITEM', 'QTY', 'TOTAL VALUASI', 'PERSENTASE %'];
                    foreach ($aging['buckets'] as $bucket) {
                        $csvData[] = [$bucket['label'], $bucket['item_count'], $bucket['total_quantity'], $bucket['total_value'], $bucket['percentage']];
                    }
                    $csvData[] = [];
                    $csvData[] = ['STOK MATI (DEAD STOCK)', 'SKU', 'NAMA ITEM', 'QTY', 'BIAYA POKOK', 'MODAL TERTANAM', 'HARI TANPA PENJUALAN', 'REKOMENDASI'];
                    $deadStock = $inventoryService->getDeadStock(30, $storeId);
                    foreach ($deadStock['items'] as $item) {
                        $csvData[] = ['', $item['sku'], $item['product_name'], $item['quantity'], $item['unit_cost'], $item['tied_up_capital'], $item['days_without_sale'], $item['recommended_action']];
                    }
                    break;

                case 'financial_bi':
                    $pl = $financialService->getProfitAndLossSummary($startDate, $endDate, $storeId);
                    $csvData[] = ['RINGKASAN LABA RUGI BUKU BESAR', 'PERIODE', "{$pl['start_date']} s/d {$pl['end_date']}"];
                    $csvData[] = ['Total Pendapatan (Revenue)', $pl['revenue']];
                    $csvData[] = ['Harga Pokok Penjualan (COGS)', $pl['cogs']];
                    $csvData[] = ['Laba Kotor (Gross Profit)', $pl['gross_profit'], "{$pl['gross_margin_pct']}%"];
                    $csvData[] = ['Beban Operasional (OpEx)', $pl['operating_expense']];
                    $csvData[] = ['Laba Operasional (Operating Profit)', $pl['operating_profit'], "{$pl['operating_margin_pct']}%"];
                    $csvData[] = ['Pendapatan Lain-lain', $pl['other_income']];
                    $csvData[] = ['Beban Lain-lain', $pl['other_expense']];
                    $csvData[] = ['Laba Bersih (Net Profit)', $pl['net_profit'], "{$pl['net_margin_pct']}%"];
                    break;

                case 'customer_analytics':
                    $clv = $customerService->getCustomerLifetimeValueSummary($storeId);
                    $csvData[] = ['CUSTOMER LIFETIME VALUE SUMMARY', 'NILAI'];
                    $csvData[] = ['Total Pelanggan Terlacak', $clv['total_tracked_customers']];
                    $csvData[] = ['Total Belanja Pelanggan', $clv['total_customer_revenue']];
                    $csvData[] = ['Average CLV', $clv['average_clv']];
                    $csvData[] = ['Average Order Value (AOV)', $clv['average_order_value']];
                    $csvData[] = [];
                    $csvData[] = ['TOP CUSTOMERS', 'NAMA', 'TELEPON', 'TOTAL BELANJA', 'JUMLAH TRANSAKSI', 'AOV'];
                    foreach ($clv['top_customers'] as $c) {
                        $csvData[] = ['', $c['name'], $c['phone'], $c['total_spend'], $c['order_count'], $c['average_order_value']];
                    }
                    break;

                default:
                    $csvData[] = ['DATA', 'KOSONG'];
                    break;
            }

            $csvOutput = fopen('php://temp', 'r+');
            if ($csvOutput === false) {
                throw new \RuntimeException('Failed to open memory stream for CSV.');
            }

            foreach ($csvData as $row) {
                fputcsv($csvOutput, (array) $row);
            }
            rewind($csvOutput);
            $csvContent = stream_get_contents($csvOutput);
            fclose($csvOutput);

            if ($csvContent === false) {
                throw new \RuntimeException('Failed to read CSV content.');
            }

            $dir = "exports/tenant-{$this->tenantId}";
            $filename = "analytics-{$type}-" . Carbon::now()->format('YmdHis') . '.csv';
            $filePath = "{$dir}/{$filename}";

            Storage::disk('local')->put($filePath, $csvContent);

            $this->exportTask->update([
                'status' => ExportTaskStatus::Completed,
                'file_path' => $filePath,
                'file_size_bytes' => strlen($csvContent),
                'completed_at' => Carbon::now(),
            ]);
        } catch (Throwable $e) {
            $this->exportTask->update([
                'status' => ExportTaskStatus::Failed,
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            if ($prevTenantId !== null) {
                $tenantContext->setTenantId($prevTenantId);
            } else {
                $tenantContext->clear();
            }

            if ($prevStoreId !== null) {
                $tenantContext->setStoreId($prevStoreId);
            }
        }
    }
}
