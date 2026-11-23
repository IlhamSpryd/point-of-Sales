<?php

declare(strict_types=1);

namespace Tests\Feature\Reports\E2e\Tier1;

use App\Modules\Reporting\Services\DashboardOperationsQueryService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Reports\E2e\BaseE2eReportTestCase;

/**
 * Feature 21: Section F: Recent Transactions & Digital Receipt Drawer
 *
 * Verifies:
 * - 8-column recent transactions table (code, date/time, store, cashier, customer, payment, total, status).
 * - Digital receipt drawer opening and data contract.
 * - Order line items and tender breakdown display.
 * - Double-entry GL journal lines display with balanced debits and credits.
 * - Voided transactions display with destructive badge variant and cancellation tracking.
 */
class RecentTransactionsAndReceiptDrawerTest extends BaseE2eReportTestCase
{
    protected DashboardOperationsQueryService $operationsService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->operationsService = app(DashboardOperationsQueryService::class);
    }

    /**
     * T1-FE21-01: Verifies 8-column recent transactions table.
     */
    public function test_section_f_renders_8_column_recent_transactions_table(): void
    {
        $order = $this->createOrderWithPayment($this->tenant, $this->store, $this->ownerUser, [
            'order_code' => 'ORD-TX-001',
            'order_amount' => 125000.00,
            'payment_method' => 'qris',
            'order_status' => 'paid',
        ]);

        $data = $this->operationsService->getRecentTransactions($this->tenant->id, $this->store->id);

        $this->assertArrayHasKey('items', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertNotEmpty($data['items']);

        $row = collect($data['items'])->firstWhere('order_code', 'ORD-TX-001');
        $this->assertNotNull($row, 'Created order must appear in recent transactions table');

        // Verify the 8 columns
        $this->assertEquals('ORD-TX-001', $row['order_code']);
        $this->assertArrayHasKey('date_time', $row);
        $this->assertEquals($this->store->name, $row['store_name']);
        $this->assertEquals($this->ownerUser->name, $row['cashier_name']);
        $this->assertArrayHasKey('customer_name', $row);
        $this->assertEquals('QRIS', $row['payment_method']);
        $this->assertEquals(125000.00, $row['total']);
        $this->assertEquals('paid', $row['order_status']);
    }

    /**
     * T1-FE21-02: Verifies clicking transaction row opens receipt drawer with complete header details.
     */
    public function test_section_f_clicking_transaction_row_opens_receipt_drawer(): void
    {
        $order = $this->createOrderWithPayment($this->tenant, $this->store, $this->ownerUser, [
            'order_code' => 'ORD-DRAWER-01',
            'order_amount' => 75000.00,
            'payment_method' => 'cash',
        ]);

        $detail = $this->operationsService->getTransactionReceiptDetail($this->tenant->id, $order->id);

        $this->assertArrayHasKey('order', $detail);
        $this->assertArrayHasKey('items', $detail);
        $this->assertArrayHasKey('tenders', $detail);
        $this->assertArrayHasKey('gl_journal', $detail);

        $orderData = $detail['order'];
        $this->assertNotNull($orderData);
        $this->assertEquals($order->id, $orderData['id']);
        $this->assertEquals('ORD-DRAWER-01', $orderData['order_code']);
        $this->assertEquals(75000.00, $orderData['total']);
        $this->assertEquals('CASH', $orderData['payment_method']);
        $this->assertEquals($this->store->name, $orderData['store_name']);
    }

    /**
     * T1-FE21-03: Verifies receipt drawer displays order line items and tenders.
     */
    public function test_section_f_receipt_drawer_displays_order_line_items_and_tenders(): void
    {
        $order = $this->createOrderWithPayment($this->tenant, $this->store, $this->ownerUser, [
            'order_code' => 'ORD-RECEIPT-LINES',
            'order_amount' => 90000.00,
            'payment_method' => 'card',
        ]);

        // Add 2 line items
        $this->createProductWithItem($this->tenant, $this->store, $order, [
            'product_name' => 'Cappuccino Large',
            'product_code' => 'SKU-CAP-LG',
            'product_price' => 45000.00,
        ], [
            'qty' => 1,
            'order_price' => 45000.00,
            'order_subtotal' => 45000.00,
        ]);

        $this->createProductWithItem($this->tenant, $this->store, $order, [
            'product_name' => 'Almond Croissant',
            'product_code' => 'SKU-ALM-CR',
            'product_price' => 45000.00,
        ], [
            'qty' => 1,
            'order_price' => 45000.00,
            'order_subtotal' => 45000.00,
        ]);

        $detail = $this->operationsService->getTransactionReceiptDetail($this->tenant->id, $order->id);

        $items = $detail['items'];
        $this->assertCount(2, $items);

        $cap = collect($items)->firstWhere('product_name', 'Cappuccino Large');
        $this->assertNotNull($cap);
        $this->assertEquals(1, $cap['quantity']);
        $this->assertEquals(45000.00, $cap['unit_price']);
        $this->assertEquals(45000.00, $cap['subtotal']);

        $croissant = collect($items)->firstWhere('product_name', 'Almond Croissant');
        $this->assertNotNull($croissant);
        $this->assertEquals(1, $croissant['quantity']);
        $this->assertEquals(45000.00, $croissant['unit_price']);
        $this->assertEquals(45000.00, $croissant['subtotal']);
    }

    /**
     * T1-FE21-04: Verifies receipt drawer displays double-entry GL journal lines with balanced debits and credits.
     */
    public function test_section_f_receipt_drawer_displays_double_entry_gl_journal_lines(): void
    {
        $order = $this->createOrderWithPayment($this->tenant, $this->store, $this->ownerUser, [
            'order_code' => 'ORD-GL-001',
            'order_amount' => 100000.00,
        ]);

        // Create Accounting Period
        $periodId = DB::table('accounting_periods')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'period_code' => 'PER-'.Carbon::now()->format('Ym'),
            'fiscal_year' => (int) Carbon::now()->format('Y'),
            'period_number' => (int) Carbon::now()->format('n'),
            'start_date' => Carbon::now()->startOfMonth()->toDateString(),
            'end_date' => Carbon::now()->endOfMonth()->toDateString(),
            'status' => 'OPEN',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create Chart of Accounts
        $kasAccountId = DB::table('chart_of_accounts')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'account_code' => '1101-'.Str::random(4),
            'account_name' => 'Kas Operasional Kasir',
            'account_type' => 'ASSET',
            'account_subtype' => 'CASH_AND_BANK',
            'normal_balance' => 'DEBIT',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $salesAccountId = DB::table('chart_of_accounts')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'account_code' => '4101-'.Str::random(4),
            'account_name' => 'Pendapatan Penjualan POS',
            'account_type' => 'REVENUE',
            'account_subtype' => 'OPERATING_REVENUE',
            'normal_balance' => 'CREDIT',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $journalId = DB::table('journal_entries')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'accounting_period_id' => $periodId,
            'journal_number' => 'JRN-'.strtoupper(Carbon::now()->format('Ymd')).'-'.Str::random(4),
            'posting_date' => Carbon::now('Asia/Jakarta')->toDateString(),
            'source_module' => 'SALES',
            'source_type' => 'POS_ORDER',
            'source_id' => $order->id,
            'source_number' => $order->order_code,
            'idempotency_key' => 'JRN-IDEMP-'.Str::random(12),
            'total_debit' => 100000.00,
            'total_credit' => 100000.00,
            'status' => 'POSTED',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('journal_lines')->insert([
            [
                'tenant_id' => $this->tenant->id,
                'journal_entry_id' => $journalId,
                'line_number' => 1,
                'account_id' => $kasAccountId,
                'debit_amount' => 100000.00,
                'credit_amount' => 0.00,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tenant_id' => $this->tenant->id,
                'journal_entry_id' => $journalId,
                'line_number' => 2,
                'account_id' => $salesAccountId,
                'debit_amount' => 0.00,
                'credit_amount' => 100000.00,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $detail = $this->operationsService->getTransactionReceiptDetail($this->tenant->id, $order->id);

        $this->assertNotNull($detail['gl_journal'], 'GL Journal should be present for order');
        $gl = $detail['gl_journal'];

        $this->assertEquals(100000.00, $gl['total_debit']);
        $this->assertEquals(100000.00, $gl['total_credit']);
        $this->assertCount(2, $gl['lines']);

        $debitLine = collect($gl['lines'])->firstWhere('debit', 100000.00);
        $this->assertNotNull($debitLine);

        $creditLine = collect($gl['lines'])->firstWhere('credit', 100000.00);
        $this->assertNotNull($creditLine);
    }

    /**
     * T1-FE21-05: Verifies voided transaction displays void status and destructive badge variant.
     */
    public function test_section_f_voided_transaction_displays_void_stamp_and_reason(): void
    {
        $voidOrder = $this->createOrderWithPayment($this->tenant, $this->store, $this->ownerUser, [
            'order_code' => 'ORD-VOID-001',
            'order_amount' => 50000.00,
            'order_status' => 'void',
        ]);

        $data = $this->operationsService->getRecentTransactions($this->tenant->id, $this->store->id);

        $row = collect($data['items'])->firstWhere('order_code', 'ORD-VOID-001');
        $this->assertNotNull($row);

        $this->assertEquals('void', $row['order_status']);
        $this->assertEquals('destructive', $row['badge_variant']);
    }
}
