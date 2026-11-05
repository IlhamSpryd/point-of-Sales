<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\OrderStatus;
use App\Models\Category;
use App\Models\Device;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\Shift;
use App\Modules\Finance\DataTransferObjects\JournalLineDto;
use App\Modules\Finance\Enums\AccountSubtypeEnum;
use App\Modules\Finance\Enums\AccountTypeEnum;
use App\Modules\Finance\Enums\JournalStatusEnum;
use App\Modules\Finance\Enums\JournalTypeEnum;
use App\Modules\Finance\Enums\NormalBalanceEnum;
use App\Modules\Finance\Models\AccountingPeriod;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Services\JournalEntryService;
use App\Modules\Reports\Services\Analytics\SalesAnalyticsService;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

class Wave7SalesAnalyticsTest extends TestCase
{
    use RefreshDatabase;
    use TwoTenantFixture;

    protected SalesAnalyticsService $analyticsService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        app(TenantContext::class)->runWithoutTenant(function () {
            $this->setupTwoTenants();
        });

        $this->analyticsService = app(SalesAnalyticsService::class);
    }

    /**
     * T-RBI-01: Query perbandingan MoM dan YoY menghasilkan delta persentase dan nominal yang akurat serta scoped ke tenant aktif.
     */
    public function test_t_rbi_01_mom_and_yoy_comparison_calculates_accurate_growth_and_tenant_isolation(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $now = Carbon::parse('2026-10-15 14:00:00');
        $prevMonth = Carbon::parse('2026-09-15 14:00:00');
        $prevYear = Carbon::parse('2025-10-15 14:00:00');

        // Tenant A: Bulan Ini (Oktober 2026) -> 2 pesanan, total Rp 300.000
        Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->userAOwner->id,
            'order_code' => 'ORD-A-CUR-1',
            'order_status' => OrderStatus::Paid->value,
            'order_amount' => 100000.00,
            'subtotal_amount' => 100000.00,
            'order_date' => '2026-10-05',
            'created_at' => '2026-10-05 10:00:00',
            'idempotency_key' => Str::uuid()->toString(),
        ]);
        Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->userAOwner->id,
            'order_code' => 'ORD-A-CUR-2',
            'order_status' => OrderStatus::Paid->value,
            'order_amount' => 200000.00,
            'subtotal_amount' => 200000.00,
            'order_date' => '2026-10-10',
            'created_at' => '2026-10-10 11:00:00',
            'idempotency_key' => Str::uuid()->toString(),
        ]);

        // Tenant A: Bulan Lalu (September 2026) -> 1 pesanan, total Rp 200.000
        Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->userAOwner->id,
            'order_code' => 'ORD-A-PREV-MOM',
            'order_status' => OrderStatus::Paid->value,
            'order_amount' => 200000.00,
            'subtotal_amount' => 200000.00,
            'order_date' => '2026-09-12',
            'created_at' => '2026-09-12 12:00:00',
            'idempotency_key' => Str::uuid()->toString(),
        ]);

        // Tenant A: Tahun Lalu (Oktober 2025) -> 1 pesanan, total Rp 150.000
        Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->userAOwner->id,
            'order_code' => 'ORD-A-PREV-YOY',
            'order_status' => OrderStatus::Paid->value,
            'order_amount' => 150000.00,
            'subtotal_amount' => 150000.00,
            'order_date' => '2025-10-10',
            'created_at' => '2025-10-10 13:00:00',
            'idempotency_key' => Str::uuid()->toString(),
        ]);

        // Tenant B: Data pencemar (Oktober 2026) -> Rp 5.000.000 (tidak boleh masuk ke Tenant A)
        $context->runAs($this->tenantB->id, $this->storeB->id, function () {
            Order::forceCreate([
                'tenant_id' => $this->tenantB->id,
                'store_id' => $this->storeB->id,
                'user_id' => $this->userBBarista->id,
                'order_code' => 'ORD-B-POLLUTE',
                'order_status' => OrderStatus::Paid->value,
                'order_amount' => 5000000.00,
                'subtotal_amount' => 5000000.00,
                'order_date' => '2026-10-08',
                'created_at' => '2026-10-08 14:00:00',
                'idempotency_key' => Str::uuid()->toString(),
            ]);
        });

        // 1. Uji MoM
        $mom = $this->analyticsService->getMomComparison($now);
        $this->assertEquals('300000.00', $mom['current_revenue']);
        $this->assertEquals('200000.00', $mom['previous_revenue']);
        $this->assertEquals(2, $mom['current_orders']);
        $this->assertEquals(1, $mom['previous_orders']);
        // Pertumbuhan: (300.000 - 200.000) / 200.000 * 100 = 50.00%
        $this->assertEquals('50.00', $mom['revenue_growth_pct']);
        $this->assertEquals('100.00', $mom['orders_growth_pct']);

        // 2. Uji YoY
        $yoy = $this->analyticsService->getYoyComparison($now);
        $this->assertEquals('300000.00', $yoy['current_revenue']);
        $this->assertEquals('150000.00', $yoy['previous_revenue']);
        // Pertumbuhan YoY: (300.000 - 150.000) / 150.000 * 100 = 100.00%
        $this->assertEquals('100.00', $yoy['revenue_growth_pct']);

        // 3. Uji Isolasi Tenant B
        $context->setTenantId($this->tenantB->id);
        $context->setStoreId($this->storeB->id);
        $momB = $this->analyticsService->getMomComparison($now);
        $this->assertEquals('5000000.00', $momB['current_revenue']);
        $this->assertEquals(1, $momB['current_orders']);
    }

    /**
     * T-RBI-02: Basket analysis menghasilkan pasangan produk co-occurrence dengan frekuensi dan confidence ratio yang benar tanpa cross-tenant data leak.
     */
    public function test_t_rbi_02_basket_analysis_identifies_co_occurrences_and_confidence(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $cat = Category::create([
            'tenant_id' => $this->tenantA->id,
            'category_name' => 'Kopi & Roti',
            'category_code' => 'CAT-KR',
        ]);

        $prodA = Product::create([
            'tenant_id' => $this->tenantA->id,
            'category_id' => $cat->id,
            'product_name' => 'Kopi Espresso',
            'product_code' => 'PRD-ESP',
            'product_price' => 25000.00,
        ]);

        $prodB = Product::create([
            'tenant_id' => $this->tenantA->id,
            'category_id' => $cat->id,
            'product_name' => 'Croissant Butter',
            'product_code' => 'PRD-CRS',
            'product_price' => 30000.00,
        ]);

        $prodC = Product::create([
            'tenant_id' => $this->tenantA->id,
            'category_id' => $cat->id,
            'product_name' => 'Mineral Water',
            'product_code' => 'PRD-WTR',
            'product_price' => 10000.00,
        ]);

        $start = Carbon::parse('2026-10-01');
        $end = Carbon::parse('2026-10-31');

        // Order 1: Prod A + Prod B
        $o1 = Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->userAOwner->id,
            'order_code' => 'ORD-BA-1',
            'order_status' => OrderStatus::Paid->value,
            'order_amount' => 55000.00,
            'subtotal_amount' => 55000.00,
            'order_date' => '2026-10-05',
            'created_at' => '2026-10-05 09:00:00',
            'idempotency_key' => Str::uuid()->toString(),
        ]);
        OrderItem::create(['order_id' => $o1->id, 'product_id' => $prodA->id, 'qty' => 1, 'order_price' => 25000.00, 'order_subtotal' => 25000.00, 'tenant_id' => $this->tenantA->id, 'store_id' => $this->storeA->id]);
        OrderItem::create(['order_id' => $o1->id, 'product_id' => $prodB->id, 'qty' => 1, 'order_price' => 30000.00, 'order_subtotal' => 30000.00, 'tenant_id' => $this->tenantA->id, 'store_id' => $this->storeA->id]);

        // Order 2: Prod A + Prod B + Prod C
        $o2 = Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->userAOwner->id,
            'order_code' => 'ORD-BA-2',
            'order_status' => OrderStatus::Paid->value,
            'order_amount' => 65000.00,
            'subtotal_amount' => 65000.00,
            'order_date' => '2026-10-06',
            'created_at' => '2026-10-06 10:00:00',
            'idempotency_key' => Str::uuid()->toString(),
        ]);
        OrderItem::create(['order_id' => $o2->id, 'product_id' => $prodA->id, 'qty' => 1, 'order_price' => 25000.00, 'order_subtotal' => 25000.00, 'tenant_id' => $this->tenantA->id, 'store_id' => $this->storeA->id]);
        OrderItem::create(['order_id' => $o2->id, 'product_id' => $prodB->id, 'qty' => 1, 'order_price' => 30000.00, 'order_subtotal' => 30000.00, 'tenant_id' => $this->tenantA->id, 'store_id' => $this->storeA->id]);
        OrderItem::create(['order_id' => $o2->id, 'product_id' => $prodC->id, 'qty' => 1, 'order_price' => 10000.00, 'order_subtotal' => 10000.00, 'tenant_id' => $this->tenantA->id, 'store_id' => $this->storeA->id]);

        // Order 3: Prod A saja
        $o3 = Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->userAOwner->id,
            'order_code' => 'ORD-BA-3',
            'order_status' => OrderStatus::Paid->value,
            'order_amount' => 25000.00,
            'subtotal_amount' => 25000.00,
            'order_date' => '2026-10-07',
            'created_at' => '2026-10-07 11:00:00',
            'idempotency_key' => Str::uuid()->toString(),
        ]);
        OrderItem::create(['order_id' => $o3->id, 'product_id' => $prodA->id, 'qty' => 1, 'order_price' => 25000.00, 'order_subtotal' => 25000.00, 'tenant_id' => $this->tenantA->id, 'store_id' => $this->storeA->id]);

        $basket = $this->analyticsService->getBasketAnalysis($start, $end, null, 10, 2);

        $this->assertNotEmpty($basket);
        $pairAB = collect($basket)->first(function ($p) use ($prodA, $prodB) {
            return ($p['product_a_id'] === $prodA->id && $p['product_b_id'] === $prodB->id)
                || ($p['product_a_id'] === $prodB->id && $p['product_b_id'] === $prodA->id);
        });

        $this->assertNotNull($pairAB);
        $this->assertEquals(2, $pairAB['co_occurrence_count']);
        // Total order = 3. Co-occurrence = 2 -> Support = 2/3 * 100 = 66.67%
        $this->assertEquals('66.67', $pairAB['support_pct']);
        // Order memuat Prod A = 3. Pasangan A+B = 2 -> Confidence = 2/3 * 100 = 66.67%
        $this->assertEquals('66.67', $pairAB['confidence_pct']);
    }

    /**
     * T-RBI-03: Heatmap jam sibuk mengembalikan matriks 24x7 dengan agregasi jumlah transaksi dan total omzet.
     */
    public function test_t_rbi_03_peak_hours_heatmap_returns_complete_24x7_matrix(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $monday = Carbon::parse('2026-10-12 14:15:00'); // 2026-10-12 adalah hari Senin

        Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->userAOwner->id,
            'order_code' => 'ORD-HEAT-1',
            'order_status' => OrderStatus::Paid->value,
            'order_amount' => 75000.00,
            'subtotal_amount' => 75000.00,
            'order_date' => $monday->toDateString(),
            'created_at' => $monday->toDateTimeString(),
            'idempotency_key' => Str::uuid()->toString(),
        ]);

        $heatmap = $this->analyticsService->getPeakHoursHeatmap(
            Carbon::parse('2026-10-01'),
            Carbon::parse('2026-10-31')
        );

        // 7 Hari dalam seminggu
        $this->assertCount(7, $heatmap);

        // Hari Senin (day_of_week = 1)
        $mondayRow = collect($heatmap)->firstWhere('day_of_week', 1);
        $this->assertNotNull($mondayRow);
        $this->assertEquals('Senin', $mondayRow['day_name']);
        $this->assertCount(24, $mondayRow['hours']);

        // Jam 14:00
        $hour14 = collect($mondayRow['hours'])->firstWhere('hour', 14);
        $this->assertNotNull($hour14);
        $this->assertEquals(1, $hour14['order_count']);
        $this->assertEquals('75000.00', $hour14['total_sales']);

        // Jam lain pada hari Senin (misal jam 03:00) kosong
        $hour03 = collect($mondayRow['hours'])->firstWhere('hour', 3);
        $this->assertNotNull($hour03);
        $this->assertEquals(0, $hour03['order_count']);
        $this->assertEquals('0.00', $hour03['total_sales']);
    }

    /**
     * T-RBI-01 (GL Reconciliation): Membuktikan perhitungan Penjualan Bersih (Net Sales) pada laporan penjualan
     * sama persis dengan saldo akun Pendapatan Penjualan (4-10000 / REVENUE) di General Ledger,
     * memperhitungkan diskon kontra (4201), retur penjualan (4202), pembatalan/reversal jurnal,
     * pengabaian jurnal DRAFT, serta isolasi tenant dan cabang.
     */
    public function test_t_rbi_01_net_sales_reconciles_with_general_ledger_4_10000_including_reversals_discounts_and_returns(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $start = Carbon::parse('2026-10-01');
        $end = Carbon::parse('2026-10-31');

        $periodA = AccountingPeriod::create([
            'tenant_id' => $this->tenantA->id,
            'period_code' => '2026-10',
            'fiscal_year' => 2026,
            'period_number' => 10,
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-31',
            'status' => 'OPEN',
        ]);

        $cashAcc = ChartOfAccount::create([
            'tenant_id' => $this->tenantA->id,
            'account_code' => '1-10000',
            'account_name' => 'Kas Operasional',
            'account_type' => AccountTypeEnum::ASSET->value,
            'account_subtype' => AccountSubtypeEnum::CASH_BANK->value,
            'normal_balance' => NormalBalanceEnum::DEBIT->value,
            'system_account_key' => 'CASH_MAIN',
            'is_active' => true,
            'is_postable' => true,
        ]);

        $revAcc = ChartOfAccount::create([
            'tenant_id' => $this->tenantA->id,
            'account_code' => '4-10000',
            'account_name' => 'Pendapatan Penjualan Kasir POS',
            'account_type' => AccountTypeEnum::REVENUE->value,
            'account_subtype' => AccountSubtypeEnum::SALES_REVENUE->value,
            'normal_balance' => NormalBalanceEnum::CREDIT->value,
            'system_account_key' => 'SALES_RETAIL',
            'is_active' => true,
            'is_postable' => true,
        ]);

        $discAcc = ChartOfAccount::create([
            'tenant_id' => $this->tenantA->id,
            'account_code' => '4201',
            'account_name' => 'Diskon Penjualan (Contra Revenue)',
            'account_type' => AccountTypeEnum::REVENUE->value,
            'account_subtype' => AccountSubtypeEnum::DISCOUNT_CONTRA->value,
            'normal_balance' => NormalBalanceEnum::DEBIT->value,
            'system_account_key' => 'SALES_DISCOUNT',
            'is_active' => true,
            'is_postable' => true,
        ]);

        $retAcc = ChartOfAccount::create([
            'tenant_id' => $this->tenantA->id,
            'account_code' => '4202',
            'account_name' => 'Retur Penjualan (Contra Revenue)',
            'account_type' => AccountTypeEnum::REVENUE->value,
            'account_subtype' => AccountSubtypeEnum::RETURN_CONTRA->value,
            'normal_balance' => NormalBalanceEnum::DEBIT->value,
            'system_account_key' => 'SALES_RETURN',
            'is_active' => true,
            'is_postable' => true,
        ]);

        $taxAcc = ChartOfAccount::create([
            'tenant_id' => $this->tenantA->id,
            'account_code' => '2-10200',
            'account_name' => 'Utang PPN Keluaran',
            'account_type' => AccountTypeEnum::LIABILITY->value,
            'account_subtype' => AccountSubtypeEnum::TAX_LIABILITY->value,
            'normal_balance' => NormalBalanceEnum::CREDIT->value,
            'system_account_key' => 'TAX_OUTPUT_PPN',
            'is_active' => true,
            'is_postable' => true,
        ]);

        // 1. Transaksi Operasional: Gross Rp 500.000, Diskon Rp 50.000, PPN Rp 49.500 -> Total Tagihan Rp 499.500
        $order = Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->userAOwner->id,
            'order_code' => 'ORD-GL-REC-01',
            'order_status' => OrderStatus::Paid->value,
            'subtotal_amount' => 500000.00,
            'discount_amount' => 50000.00,
            'tax_amount' => 49500.00,
            'order_amount' => 499500.00,
            'order_date' => '2026-10-10',
            'created_at' => '2026-10-10 10:00:00',
            'idempotency_key' => Str::uuid()->toString(),
        ]);

        $shift = Shift::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->userAOwner->id,
            'active_cashier_id' => $this->userAOwner->id,
            'starting_balance' => 100000,
            'status' => 'open',
            'opened_at' => '2026-10-10 08:00:00',
        ]);

        $device = Device::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'device_name' => 'POS Register GL',
            'device_code' => 'DEV-GL-01',
            'device_type' => 'pos',
            'status' => 'active',
        ]);

        // 2. Retur Penjualan Selesai: Subtotal Retur Rp 30.000, PPN Retur Rp 3.300 -> Total Refund Rp 33.300
        OrderReturn::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'order_id' => $order->id,
            'shift_id' => $shift->id,
            'device_id' => $device->id,
            'return_number' => 'RET-GL-REC-01',
            'return_type' => OrderReturn::TYPE_PARTIAL,
            'reason' => 'defective',
            'subtotal_returned' => 30000.00,
            'tax_returned' => 3300.00,
            'service_charge_returned' => 0.00,
            'total_refundable_amount' => 33300.00,
            'status' => OrderReturn::STATUS_COMPLETED,
            'requested_by_user_id' => $this->userAOwner->id,
            'approved_by_user_id' => $this->userAOwner->id,
            'completed_at' => '2026-10-11 11:00:00',
            'idempotency_key' => Str::uuid()->toString(),
        ]);

        $journalService = app(JournalEntryService::class);

        // 3. Posting Jurnal Penjualan (POSTED) ke GL
        $journalService->createAndPostJournal(
            tenantId: $this->tenantA->id,
            storeId: $this->storeA->id,
            postingDate: '2026-10-10',
            idempotencyKey: 'GL-REC-SALE-01',
            lines: [
                new JournalLineDto(accountId: $cashAcc->id, debitAmount: '499500.00', creditAmount: '0.00', description: 'Kas Penjualan'),
                new JournalLineDto(accountId: $discAcc->id, debitAmount: '50000.00', creditAmount: '0.00', description: 'Diskon Penjualan'),
                new JournalLineDto(accountId: $revAcc->id, debitAmount: '0.00', creditAmount: '500000.00', description: 'Pendapatan Penjualan 4-10000'),
                new JournalLineDto(accountId: $taxAcc->id, debitAmount: '0.00', creditAmount: '49500.00', description: 'PPN Keluaran'),
            ],
            sourceModule: 'POS',
            sourceType: 'ORDER',
            sourceId: $order->id,
            sourceNumber: $order->order_code,
            actor: $this->userAOwner
        );

        // 4. Posting Jurnal Retur Penjualan (POSTED) ke GL
        $journalService->createAndPostJournal(
            tenantId: $this->tenantA->id,
            storeId: $this->storeA->id,
            postingDate: '2026-10-11',
            idempotencyKey: 'GL-REC-RET-01',
            lines: [
                new JournalLineDto(accountId: $retAcc->id, debitAmount: '30000.00', creditAmount: '0.00', description: 'Retur Penjualan'),
                new JournalLineDto(accountId: $taxAcc->id, debitAmount: '3300.00', creditAmount: '0.00', description: 'Pembatalan PPN'),
                new JournalLineDto(accountId: $cashAcc->id, debitAmount: '0.00', creditAmount: '33300.00', description: 'Refund Kas'),
            ],
            sourceModule: 'SALES',
            sourceType: 'ORDER_RETURN',
            actor: $this->userAOwner
        );

        // 5. Posting Jurnal Salah Input Rp 200.000 lalu di-REVERSE (efek bersih wajib 0.00)
        $erroneousJournal = $journalService->createAndPostJournal(
            tenantId: $this->tenantA->id,
            storeId: $this->storeA->id,
            postingDate: '2026-10-12',
            idempotencyKey: 'GL-REC-ERR-01',
            lines: [
                new JournalLineDto(accountId: $cashAcc->id, debitAmount: '200000.00', creditAmount: '0.00', description: 'Salah Input'),
                new JournalLineDto(accountId: $revAcc->id, debitAmount: '0.00', creditAmount: '200000.00', description: 'Salah Input Pendapatan'),
            ],
            actor: $this->userAOwner
        );
        $journalService->reverseJournal($erroneousJournal, '2026-10-12', 'Koreksi salah input nominal', $this->userAOwner);

        // 6. Jurnal berstatus DRAFT sebesar Rp 900.000 (wajib diabaikan dari perhitungan Net Sales)
        $draftJournal = JournalEntry::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'journal_number' => 'DRAFT-202610-99',
            'accounting_period_id' => $periodA->id,
            'posting_date' => '2026-10-15',
            'journal_type' => JournalTypeEnum::MANUAL_ADJUSTMENT->value,
            'status' => JournalStatusEnum::DRAFT->value,
            'source_module' => 'FINANCE',
            'source_type' => 'MANUAL',
            'idempotency_key' => 'GL-REC-DRAFT-01',
            'currency_code' => 'IDR',
            'total_debit' => '900000.00',
            'total_credit' => '900000.00',
        ]);
        JournalLine::create([
            'tenant_id' => $this->tenantA->id,
            'journal_entry_id' => $draftJournal->id,
            'line_number' => 1,
            'account_id' => $cashAcc->id,
            'store_id' => $this->storeA->id,
            'debit_amount' => '900000.00',
            'credit_amount' => '0.00',
        ]);
        JournalLine::create([
            'tenant_id' => $this->tenantA->id,
            'journal_entry_id' => $draftJournal->id,
            'line_number' => 2,
            'account_id' => $revAcc->id,
            'store_id' => $this->storeA->id,
            'debit_amount' => '0.00',
            'credit_amount' => '900000.00',
        ]);

        // 7. Rekonsiliasi Penuh:
        // Gross Sales = 500.000, Diskon = 50.000, Retur = 30.000 -> Net Sales = 420.000,00 (PPN 49.500 tidak masuk Net Sales)
        $reconciliation = $this->analyticsService->reconcileNetSalesWithGl($start, $end, $this->storeA->id);

        $this->assertTrue($reconciliation['is_reconciled'], 'Laporan Penjualan Bersih wajib terekonsiliasi dengan GL.');
        $this->assertSame('0.00', $reconciliation['variance']);
        $this->assertSame('500000.00', $reconciliation['operational_gross_sales']);
        $this->assertSame('50000.00', $reconciliation['operational_discounts']);
        $this->assertSame('30000.00', $reconciliation['operational_returns']);
        $this->assertSame('420000.00', $reconciliation['operational_net_sales']);
        $this->assertSame('500000.00', $reconciliation['gl_gross_revenue']);
        $this->assertSame('50000.00', $reconciliation['gl_discounts']);
        $this->assertSame('30000.00', $reconciliation['gl_returns']);
        $this->assertSame('420000.00', $reconciliation['gl_net_sales']);

        // Verifikasi akun spesifik 4-10000 (Gross Sales Credit 500.000 + Reversed 200.000 - Reversal 200.000 = 500.000)
        $this->assertSame('500000.00', $this->analyticsService->getGlNetSales($start, $end, $this->storeA->id, '4-10000'));

        // Verifikasi getSalesTrend bulanan menghasilkan Net Sales yang sama persis dengan GL (420.000,00)
        $monthlyTrend = $this->analyticsService->getSalesTrend($start, $end, 'month', $this->storeA->id);
        $this->assertCount(1, $monthlyTrend);
        $this->assertSame('2026-10', $monthlyTrend[0]['period_key']);
        $this->assertSame('500000.00', $monthlyTrend[0]['gross_sales']);
        $this->assertSame('50000.00', $monthlyTrend[0]['discount_amount']);
        $this->assertSame('30000.00', $monthlyTrend[0]['return_amount']);
        $this->assertSame('420000.00', $monthlyTrend[0]['net_sales']);
        $this->assertSame('420000.00', $monthlyTrend[0]['gl_net_sales']);
    }
}
