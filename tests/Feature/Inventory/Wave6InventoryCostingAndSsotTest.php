<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Core\Context\TenantContext;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\StockOpnameDriftResolutionEnum;
use App\Enums\StockOpnameStatusEnum;
use App\Enums\SupplierStatusEnum;
use App\Exports\SalesExport;
use App\Modules\Catalog\Models\Product;
use App\Modules\Finance\Enums\AccountingPeriodStatusEnum;
use App\Modules\Finance\Enums\JournalStatusEnum;
use App\Modules\Finance\Exceptions\PeriodClosedException;
use App\Modules\Finance\Models\AccountingPeriod;
use App\Modules\Finance\Models\FinancialEventOutbox;
use App\Modules\Finance\Services\InventoryAccountingService;
use App\Modules\Finance\Services\PostingEngineService;
use App\Modules\Inventory\Models\StockOpname;
use App\Modules\Inventory\Models\StockOpnameItem;
use App\Modules\Inventory\Services\InventoryCostingService;
use App\Modules\Organization\Models\Device;
use App\Modules\Organization\Models\User;
use App\Modules\Purchasing\Models\GoodsReceivedNote;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Reporting\Services\Providers\SalesAndPosMetricProvider;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderReturn;
use App\Modules\Sales\Models\Shift;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DefaultPostingRulesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Wave6InventoryCostingAndSsotTest extends TestCase
{
    use RefreshDatabase;

    protected $tenant;

    protected $store;

    protected User $user;

    protected Shift $shift;

    protected Device $device;

    protected AccountingPeriod $period;

    protected InventoryAccountingService $inventoryAccounting;

    protected InventoryCostingService $costingService;

    protected PostingEngineService $postingEngine;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = $this->defaultTenant;
        $this->store = $this->defaultStore;

        app(TenantContext::class)->setTenantId($this->tenant->id);

        app(ChartOfAccountsSeeder::class)->seedForTenant($this->tenant->id);
        app(DefaultPostingRulesSeeder::class)->seedForTenant($this->tenant->id);

        $this->inventoryAccounting = app(InventoryAccountingService::class);
        $this->costingService = app(InventoryCostingService::class);
        $this->postingEngine = app(PostingEngineService::class);

        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->shift = Shift::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'active_cashier_id' => $this->user->id,
            'starting_balance' => 500000,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $this->device = Device::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'device_name' => 'POS Register 01',
            'device_code' => 'DEV-01',
            'device_type' => 'pos',
            'status' => 'active',
        ]);

        $this->period = AccountingPeriod::create([
            'tenant_id' => $this->tenant->id,
            'period_code' => now()->format('Y-m'),
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
            'fiscal_year' => (int) now()->format('Y'),
            'period_number' => (int) now()->format('n'),
            'status' => AccountingPeriodStatusEnum::OPEN->value,
            'is_adjustment_period' => false,
        ]);
    }

    /**
     * INV-01: Stock Opname dengan selisih fisik minus (loss) 10 pcs @ Rp 150.000
     * menghasilkan jurnal GL selisih stok Rp 1.500.000 (bukan Rp 0,00).
     */
    public function test_stock_opname_loss_posts_accurate_gl_valuation(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_name' => 'Premium Olive Oil 1L',
            'product_price' => 200000,
            'standard_cost' => 150000,
            'cost_price' => 150000,
            'stock' => 50,
        ]);

        $opname = StockOpname::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'opname_number' => 'SO-W6-TEST-001',
            'idempotency_key' => 'IDEM-SO-W6-001',
            'status' => StockOpnameStatusEnum::Approved,
            'created_by' => $this->user->id,
            'approved_by' => $this->user->id,
            'approved_at' => now(),
        ]);

        // Catat item opname dengan variance -10 pcs
        StockOpnameItem::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'stock_opname_id' => $opname->id,
            'item_type' => 'product',
            'product_id' => $product->id,
            'snapshot_on_hand' => 50,
            'counted_quantity' => 40,
            'variance_quantity' => -10,
            'drift_resolution_mode' => StockOpnameDriftResolutionEnum::None,
        ]);

        // Rekam akuntansi stock opname
        $outbox = $this->inventoryAccounting->recordStockOpnameApproved($opname);
        $this->assertNotNull($outbox);
        $this->assertEquals('1500000.00', $outbox->payload['negative_amount']);
        $this->assertEquals('1500000.00', $outbox->payload['negative_retail_amount']);
        $this->assertEquals('0.00', $outbox->payload['positive_amount']);

        // Posting jurnal dan verifikasi
        $journal = $this->postingEngine->processOutboxEvent($outbox);
        $this->assertNotNull($journal);
        $this->assertEquals(JournalStatusEnum::POSTED, $journal->status);
        $this->assertEquals('1500000.00', $journal->total_debit);
        $this->assertEquals('1500000.00', $journal->total_credit);

        // Debit Beban Kerugian/Waste 1.500.000 vs Credit Persediaan Retail 1.500.000
        $debitLine = $journal->lines->first(fn ($l) => $l->account->system_account_key === 'INVENTORY_WASTE_EXPENSE');
        $this->assertNotNull($debitLine);
        $this->assertEquals('1500000.00', $debitLine->debit_amount);

        $creditLine = $journal->lines->first(fn ($l) => $l->account->system_account_key === 'INVENTORY_RETAIL_PRODUCT');
        $this->assertNotNull($creditLine);
        $this->assertEquals('1500000.00', $creditLine->credit_amount);
    }

    /**
     * INV-02: Moving Average saat stok negatif (-5 pcs) menerima GRN baru @ Rp 20.000
     * me-reset moving average langsung ke Rp 20.000,00 tanpa distorsi negatif.
     */
    public function test_moving_average_resets_cleanly_on_negative_stock(): void
    {
        $result = $this->costingService->calculateMovingAverage(
            currentQty: -5,
            currentAvgCost: 10000,
            incomingQty: 10,
            incomingCost: 20000,
            landedCost: 0
        );

        $this->assertEquals('20000.0000', $result['new_average_cost']);
        $this->assertEquals('5.0000', $result['total_quantity']);
        $this->assertEquals('20000.0000', $result['effective_unit_cost']);
    }

    /**
     * SSOT-01: Paritas Net Sales antara SalesExport.php dan SalesAndPosMetricProvider
     * dihitung murni berbasis DPP (subtotal - discount - retur_dpp).
     */
    public function test_ssot_parity_between_sales_export_and_metric_provider(): void
    {
        // 1. Buat Order: Subtotal 100.000, Diskon 10.000, Pajak 9.000, SC 5.000 -> Total 104.000
        $order = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'shift_id' => $this->shift->id,
            'created_by_device_id' => $this->device->id,
            'order_code' => 'ORD-SSOT-TEST-01',
            'order_date' => now()->toDateString(),
            'idempotency_key' => 'IDEM-SSOT-01',
            'subtotal_amount' => '100000.00',
            'discount_amount' => '10000.00',
            'dpp_amount' => '90000.00',
            'service_charge_amount' => '5000.00',
            'tax_amount' => '9000.00',
            'order_amount' => '104000.00',
            'cash_received' => '104000.00',
            'order_change' => '0.00',
            'order_status' => OrderStatus::Paid,
            'payment_method' => PaymentMethod::Cash,
            'order_type' => 'dine_in',
        ]);

        // 2. Buat Retur Selesai: Subtotal 40.000, Diskon Reversal 4.000 -> DPP Returned = 36.000
        OrderReturn::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $order->id,
            'shift_id' => $this->shift->id,
            'device_id' => $this->device->id,
            'return_number' => 'RET-SSOT-01',
            'idempotency_key' => 'IDEM-RET-SSOT-01',
            'status' => OrderReturn::STATUS_COMPLETED,
            'return_type' => 'partial',
            'reason' => 'Defective',
            'requested_by_user_id' => $this->user->id,
            'approved_by_user_id' => $this->user->id,
            'subtotal_returned' => '40000.00',
            'discount_reversed' => '4000.00',
            'tax_returned' => '3600.00',
            'service_charge_returned' => '2000.00',
            'total_refundable_amount' => '41600.00',
            'completed_at' => now(),
        ]);

        // Hitung melalui Metric Provider
        $metricProvider = app(SalesAndPosMetricProvider::class);
        $expectedNetSales = $metricProvider->calculateNetSales(
            grossSales: '100000.00',
            totalDiscounts: '10000.00',
            salesReturns: '36000.00' // DPP retur = 40.000 - 4.000
        );
        $this->assertEquals('54000.00', $expectedNetSales);

        // Evaluasi baris ekspor Excel
        $export = new SalesExport(now()->startOfDay(), now()->endOfDay(), true);
        $order->load(['orderReturns' => fn ($q) => $q->where('status', 'completed'), 'user']);
        $mappedRow = $export->map($order);

        // Kolom Net Sales berada pada indeks 7
        // [Tanggal, Kode, Kasir, Metode, Subtotal(Gross), Diskon, Retur(DPP), Net Sales, SC, Pajak, Total]
        $this->assertEquals(54000.00, $mappedRow[7]);
        $this->assertEquals(36000.00, $mappedRow[6]); // Retur DPP
        $this->assertEquals(100000.00, $mappedRow[4]); // Gross Sales
        $this->assertEquals(10000.00, $mappedRow[5]); // Diskon
    }

    /**
     * SIMULASI C (FIN-05):
     * Dokumen GRN bertanggal mundur ke periode yang sudah HARD_CLOSED
     * ditolak fail-fast dengan PeriodClosedException, dan 0 record outbox terbentuk.
     */
    public function test_simulation_c_backdated_grn_to_hard_closed_period_fails_fast(): void
    {
        // 1. Buat Periode Bulan Lalu yang sudah HARD_CLOSED
        $lastMonth = now()->subMonth();
        $closedPeriod = AccountingPeriod::create([
            'tenant_id' => $this->tenant->id,
            'period_code' => $lastMonth->format('Y-m'),
            'start_date' => $lastMonth->copy()->startOfMonth()->toDateString(),
            'end_date' => $lastMonth->copy()->endOfMonth()->toDateString(),
            'fiscal_year' => (int) $lastMonth->format('Y'),
            'period_number' => (int) $lastMonth->format('n'),
            'status' => AccountingPeriodStatusEnum::CLOSED->value,
            'is_adjustment_period' => false,
        ]);

        $initialOutboxCount = FinancialEventOutbox::where('tenant_id', $this->tenant->id)->count();

        $supplier = Supplier::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'SUP-W6-01',
            'name' => 'Supplier Wave 6',
            'status' => SupplierStatusEnum::Active,
        ]);

        $po = PurchaseOrder::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'supplier_id' => $supplier->id,
            'po_number' => 'PO-W6-001',
            'order_date' => $lastMonth->copy()->startOfMonth()->toDateString(),
            'idempotency_key' => 'IDEM-PO-W6-001',
            'created_by_user_id' => $this->user->id,
            'status' => 'approved',
        ]);

        // 2. Buat GRN dengan tanggal penerimaan di periode tertutup tersebut
        $grn = GoodsReceivedNote::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'supplier_id' => $supplier->id,
            'purchase_order_id' => $po->id,
            'grn_number' => 'GRN-W6-CLOSED-001',
            'status' => 'posted',
            'received_at' => $lastMonth->copy()->startOfMonth()->addDays(5)->toDateTimeString(),
            'received_by_user_id' => $this->user->id,
            'posted_at' => $lastMonth->copy()->startOfMonth()->addDays(5)->toDateTimeString(),
            'posted_by_user_id' => $this->user->id,
        ]);

        // 3. Verifikasi: recordGrnPosted wajib melempar PeriodClosedException secara sinkron
        $this->expectException(PeriodClosedException::class);

        try {
            $this->inventoryAccounting->recordGrnPosted($grn);
        } finally {
            // Pastikan tidak ada pesan outbox yang tersimpan/teracuni di DB
            $afterCount = FinancialEventOutbox::where('tenant_id', $this->tenant->id)->count();
            $this->assertEquals($initialOutboxCount, $afterCount, 'Outbox tidak boleh mencatat event untuk periode HARD_CLOSED');
        }
    }
}
