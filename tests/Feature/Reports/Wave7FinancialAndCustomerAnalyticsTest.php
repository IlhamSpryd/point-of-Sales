<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Modules\Finance\Enums\AccountSubtypeEnum;
use App\Modules\Finance\Enums\AccountTypeEnum;
use App\Modules\Finance\Enums\JournalStatusEnum;
use App\Modules\Finance\Enums\JournalTypeEnum;
use App\Modules\Finance\Enums\NormalBalanceEnum;
use App\Modules\Finance\Models\AccountingPeriod;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Reports\Services\Analytics\CustomerAnalyticsService;
use App\Modules\Reports\Services\Analytics\FinancialBiService;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

class Wave7FinancialAndCustomerAnalyticsTest extends TestCase
{
    use RefreshDatabase;
    use TwoTenantFixture;

    protected FinancialBiService $financialService;

    protected CustomerAnalyticsService $customerService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        app(TenantContext::class)->runWithoutTenant(function () {
            $this->setupTwoTenants();
        });

        $this->financialService = app(FinancialBiService::class);
        $this->customerService = app(CustomerAnalyticsService::class);
    }

    /**
     * T-RBI-07: Financial Profit & Loss summary calculation with multi-tenant isolation.
     */
    public function test_t_rbi_07_financial_profit_and_loss_summary_calculates_margins_with_tenant_isolation(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $start = Carbon::parse('2026-10-01');
        $end = Carbon::parse('2026-10-31');

        // Setup Accounting Period Tenant A
        $periodA = AccountingPeriod::create([
            'tenant_id' => $this->tenantA->id,
            'period_code' => '2026-10-A',
            'fiscal_year' => 2026,
            'period_number' => 10,
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-31',
            'status' => 'OPEN',
        ]);

        // Setup COA Tenant A
        $cashAccA = ChartOfAccount::create([
            'tenant_id' => $this->tenantA->id,
            'account_code' => '1101-A',
            'account_name' => 'Kas Operasional',
            'account_type' => AccountTypeEnum::ASSET->value,
            'account_subtype' => AccountSubtypeEnum::CASH_BANK->value,
            'normal_balance' => NormalBalanceEnum::DEBIT->value,
            'system_account_key' => 'CASH_MAIN_A_'.Str::random(5),
        ]);

        $revAccA = ChartOfAccount::create([
            'tenant_id' => $this->tenantA->id,
            'account_code' => '4101-A',
            'account_name' => 'Pendapatan Penjualan Ritel',
            'account_type' => AccountTypeEnum::REVENUE->value,
            'account_subtype' => AccountSubtypeEnum::SALES_REVENUE->value,
            'normal_balance' => NormalBalanceEnum::CREDIT->value,
            'system_account_key' => 'REV_RETAIL_A_'.Str::random(5),
        ]);

        $cogsAccA = ChartOfAccount::create([
            'tenant_id' => $this->tenantA->id,
            'account_code' => '5101-A',
            'account_name' => 'Beban Pokok Penjualan',
            'account_type' => AccountTypeEnum::COGS->value,
            'account_subtype' => AccountSubtypeEnum::COGS_RETAIL->value,
            'normal_balance' => NormalBalanceEnum::DEBIT->value,
            'system_account_key' => 'COGS_RETAIL_A_'.Str::random(5),
        ]);

        $expAccA = ChartOfAccount::create([
            'tenant_id' => $this->tenantA->id,
            'account_code' => '6101-A',
            'account_name' => 'Beban Listrik & Air',
            'account_type' => AccountTypeEnum::EXPENSE->value,
            'account_subtype' => AccountSubtypeEnum::OPERATING_EXPENSE->value,
            'normal_balance' => NormalBalanceEnum::DEBIT->value,
            'system_account_key' => 'EXP_UTIL_A_'.Str::random(5),
        ]);

        $otherIncAccA = ChartOfAccount::create([
            'tenant_id' => $this->tenantA->id,
            'account_code' => '7101-A',
            'account_name' => 'Pendapatan Bunga Bank',
            'account_type' => AccountTypeEnum::OTHER_INCOME->value,
            'account_subtype' => AccountSubtypeEnum::OTHER->value,
            'normal_balance' => NormalBalanceEnum::CREDIT->value,
            'system_account_key' => 'INC_INT_A_'.Str::random(5),
        ]);

        // Journal Entry Tenant A: Posted
        $jeA = JournalEntry::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'journal_number' => 'JV-202610-001',
            'accounting_period_id' => $periodA->id,
            'posting_date' => '2026-10-15',
            'journal_type' => JournalTypeEnum::SYSTEM_AUTO->value,
            'status' => JournalStatusEnum::POSTED->value,
            'source_module' => 'SALES',
            'source_type' => 'POS_CLOSE',
            'idempotency_key' => Str::uuid()->toString(),
            'currency_code' => 'IDR',
            'total_debit' => 16000000.00,
            'total_credit' => 16000000.00,
        ]);

        // Revenue: Credit 10.000.000
        JournalLine::create([
            'tenant_id' => $this->tenantA->id,
            'journal_entry_id' => $jeA->id,
            'line_number' => 1,
            'account_id' => $revAccA->id,
            'store_id' => $this->storeA->id,
            'debit_amount' => 0.00,
            'credit_amount' => 10000000.00,
        ]);
        // COGS: Debit 4.000.000
        JournalLine::create([
            'tenant_id' => $this->tenantA->id,
            'journal_entry_id' => $jeA->id,
            'line_number' => 2,
            'account_id' => $cogsAccA->id,
            'store_id' => $this->storeA->id,
            'debit_amount' => 4000000.00,
            'credit_amount' => 0.00,
        ]);
        // OpEx: Debit 1.500.000
        JournalLine::create([
            'tenant_id' => $this->tenantA->id,
            'journal_entry_id' => $jeA->id,
            'line_number' => 3,
            'account_id' => $expAccA->id,
            'store_id' => $this->storeA->id,
            'debit_amount' => 1500000.00,
            'credit_amount' => 0.00,
        ]);
        // Other Income: Credit 500.000
        JournalLine::create([
            'tenant_id' => $this->tenantA->id,
            'journal_entry_id' => $jeA->id,
            'line_number' => 4,
            'account_id' => $otherIncAccA->id,
            'store_id' => $this->storeA->id,
            'debit_amount' => 0.00,
            'credit_amount' => 500000.00,
        ]);
        // Cash offset: Debit 5.000.000
        JournalLine::create([
            'tenant_id' => $this->tenantA->id,
            'journal_entry_id' => $jeA->id,
            'line_number' => 5,
            'account_id' => $cashAccA->id,
            'store_id' => $this->storeA->id,
            'debit_amount' => 5000000.00,
            'credit_amount' => 0.00,
        ]);

        // Tenant B: Journal Entry to test isolation
        $context->runAs($this->tenantB->id, $this->storeB->id, function () {
            $periodB = AccountingPeriod::create([
                'tenant_id' => $this->tenantB->id,
                'period_code' => '2026-10-B',
                'fiscal_year' => 2026,
                'period_number' => 10,
                'start_date' => '2026-10-01',
                'end_date' => '2026-10-31',
                'status' => 'OPEN',
            ]);
            $revAccB = ChartOfAccount::create([
                'tenant_id' => $this->tenantB->id,
                'account_code' => '4101-B',
                'account_name' => 'Pendapatan Tenant B',
                'account_type' => AccountTypeEnum::REVENUE->value,
                'account_subtype' => AccountSubtypeEnum::SALES_REVENUE->value,
                'normal_balance' => NormalBalanceEnum::CREDIT->value,
                'system_account_key' => 'REV_TB_'.Str::random(5),
            ]);
            $jeB = JournalEntry::create([
                'tenant_id' => $this->tenantB->id,
                'store_id' => $this->storeB->id,
                'journal_number' => 'JV-TB-001',
                'accounting_period_id' => $periodB->id,
                'posting_date' => '2026-10-15',
                'journal_type' => JournalTypeEnum::SYSTEM_AUTO->value,
                'status' => JournalStatusEnum::POSTED->value,
                'source_module' => 'SALES',
                'source_type' => 'POS_CLOSE',
                'idempotency_key' => Str::uuid()->toString(),
                'currency_code' => 'IDR',
                'total_debit' => 50000000.00,
                'total_credit' => 50000000.00,
            ]);
            JournalLine::create([
                'tenant_id' => $this->tenantB->id,
                'journal_entry_id' => $jeB->id,
                'line_number' => 1,
                'account_id' => $revAccB->id,
                'store_id' => $this->storeB->id,
                'debit_amount' => 0.00,
                'credit_amount' => 50000000.00,
            ]);
        });

        // Run as Tenant A
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $pl = $this->financialService->getProfitAndLossSummary($start, $end, $this->storeA->id);

        // Revenue: 10.000.000
        $this->assertEquals('10000000.00', $pl['revenue']);
        // COGS: 4.000.000
        $this->assertEquals('4000000.00', $pl['cogs']);
        // Gross Profit: 10M - 4M = 6.000.000 (60.00%)
        $this->assertEquals('6000000.00', $pl['gross_profit']);
        $this->assertEquals('60.00', $pl['gross_margin_pct']);
        // OpEx: 1.500.000
        $this->assertEquals('1500000.00', $pl['operating_expense']);
        // Operating Profit: 6M - 1.5M = 4.500.000 (45.00%)
        $this->assertEquals('4500000.00', $pl['operating_profit']);
        $this->assertEquals('45.00', $pl['operating_margin_pct']);
        // Other income: 500.000
        $this->assertEquals('500000.00', $pl['other_income']);
        // Net Profit: 4.5M + 0.5M = 5.000.000 (50.00%)
        $this->assertEquals('5000000.00', $pl['net_profit']);
        $this->assertEquals('50.00', $pl['net_margin_pct']);

        // Test Tenant B Isolation
        $context->setTenantId($this->tenantB->id);
        $context->setStoreId($this->storeB->id);
        $plB = $this->financialService->getProfitAndLossSummary($start, $end, $this->storeB->id);
        $this->assertEquals('50000000.00', $plB['revenue']);
        $this->assertEquals('0.00', $plB['cogs']);
    }

    /**
     * T-RBI-08: Customer Lifetime Value (CLV), RFM Segmentation, and Read-Only Loyalty Ledger.
     */
    public function test_t_rbi_08_customer_lifetime_value_rfm_segmentation_and_loyalty_ledger(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $now = Carbon::now();

        // 1. Setup Customers
        $custChampions = Customer::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Budi Santoso',
            'phone' => '081234567801',
            'email' => 'budi@test.com',
            'is_active' => true,
        ]);

        $custPotential = Customer::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Siti Nurhaliza',
            'phone' => '081234567802',
            'email' => 'siti@test.com',
            'is_active' => true,
        ]);

        $custAtRisk = Customer::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Dewi Lestari',
            'phone' => '081234567803',
            'email' => 'dewi@test.com',
            'is_active' => true,
        ]);

        // Budi: 5 paid orders, total Rp 1.000.000, recent (5 days ago) -> CHAMPIONS
        for ($i = 1; $i <= 5; $i++) {
            Order::forceCreate([
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA->id,
                'user_id' => $this->userAOwner->id,
                'customer_id' => $custChampions->id,
                'order_code' => "ORD-BUDI-{$i}",
                'order_status' => OrderStatus::Paid->value,
                'order_amount' => 200000.00,
                'subtotal_amount' => 200000.00,
                'order_date' => $now->copy()->subDays(5)->toDateString(),
                'created_at' => $now->copy()->subDays(5)->toDateTimeString(),
                'idempotency_key' => Str::uuid()->toString(),
            ]);
        }

        // Siti: 1 paid order, total Rp 200.000, recent (10 days ago) -> POTENTIAL_LOYALIST
        Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->userAOwner->id,
            'customer_id' => $custPotential->id,
            'order_code' => 'ORD-SITI-1',
            'order_status' => OrderStatus::Paid->value,
            'order_amount' => 200000.00,
            'subtotal_amount' => 200000.00,
            'order_date' => $now->copy()->subDays(10)->toDateString(),
            'created_at' => $now->copy()->subDays(10)->toDateTimeString(),
            'idempotency_key' => Str::uuid()->toString(),
        ]);

        // Dewi: 3 paid orders, total Rp 600.000, older (70 days ago) -> AT_RISK
        for ($i = 1; $i <= 3; $i++) {
            Order::forceCreate([
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA->id,
                'user_id' => $this->userAOwner->id,
                'customer_id' => $custAtRisk->id,
                'order_code' => "ORD-DEWI-{$i}",
                'order_status' => OrderStatus::Paid->value,
                'order_amount' => 200000.00,
                'subtotal_amount' => 200000.00,
                'order_date' => $now->copy()->subDays(70)->toDateString(),
                'created_at' => $now->copy()->subDays(70)->toDateTimeString(),
                'idempotency_key' => Str::uuid()->toString(),
            ]);
        }

        // 2. Setup Loyalty Ledger Entries (Read-Only Source)
        DB::table('loyalty_ledger')->insert([
            [
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA->id,
                'customer_id' => $custChampions->id,
                'order_id' => null,
                'type' => 'earn',
                'points' => 100,
                'balance_after' => 100,
                'reference' => 'TX-EARN-1',
                'prev_hash' => null,
                'entry_hash' => hash('sha256', 'hash-1'),
                'created_at' => $now->copy()->subDays(3)->toDateTimeString(),
            ],
            [
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA->id,
                'customer_id' => $custChampions->id,
                'order_id' => null,
                'type' => 'bonus',
                'points' => 50,
                'balance_after' => 150,
                'reference' => 'TX-BONUS-1',
                'prev_hash' => hash('sha256', 'hash-1'),
                'entry_hash' => hash('sha256', 'hash-2'),
                'created_at' => $now->copy()->subDays(2)->toDateTimeString(),
            ],
            [
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA->id,
                'customer_id' => $custChampions->id,
                'order_id' => null,
                'type' => 'redeem',
                'points' => -30,
                'balance_after' => 120,
                'reference' => 'TX-REDEEM-1',
                'prev_hash' => hash('sha256', 'hash-2'),
                'entry_hash' => hash('sha256', 'hash-3'),
                'created_at' => $now->copy()->subDays(1)->toDateTimeString(),
            ],
        ]);

        // A. Verify CLV Summary
        $clv = $this->customerService->getCustomerLifetimeValueSummary($this->storeA->id);

        $this->assertEquals(3, $clv['total_tracked_customers']);
        // Total customer revenue = 1.000.000 + 200.000 + 600.000 = 1.800.000
        $this->assertEquals('1800000.00', $clv['total_customer_revenue']);
        $this->assertEquals(9, $clv['total_customer_orders']);
        // Average CLV = 1.800.000 / 3 = 600.000
        $this->assertEquals('600000.00', $clv['average_clv']);
        // Average frequency = 9 / 3 = 3.00
        $this->assertEquals('3.00', $clv['average_order_frequency']);
        // Average order value = 1.800.000 / 9 = 200.000
        $this->assertEquals('200000.00', $clv['average_order_value']);

        // Top Spender #1 must be Budi Santoso (1.000.000)
        $this->assertNotEmpty($clv['top_customers']);
        $this->assertEquals($custChampions->id, $clv['top_customers'][0]['customer_id']);
        $this->assertEquals('1000000.00', $clv['top_customers'][0]['total_spend']);

        // B. Verify RFM Segmentation
        $rfm = $this->customerService->getRfmSegmentation($this->storeA->id);

        $this->assertEquals(3, $rfm['total_segmented_customers']);
        $this->assertEquals(1, $rfm['segments']['CHAMPIONS']['customer_count']);
        $this->assertEquals('1000000.00', $rfm['segments']['CHAMPIONS']['total_revenue']);

        $this->assertEquals(1, $rfm['segments']['POTENTIAL_LOYALIST']['customer_count']);
        $this->assertEquals('200000.00', $rfm['segments']['POTENTIAL_LOYALIST']['total_revenue']);

        $this->assertEquals(1, $rfm['segments']['AT_RISK']['customer_count']);
        $this->assertEquals('600000.00', $rfm['segments']['AT_RISK']['total_revenue']);

        // C. Verify Loyalty Points Movement (Read-Only)
        $startLoyalty = $now->copy()->subDays(10);
        $endLoyalty = $now->copy()->addDay();
        $loyalty = $this->customerService->getLoyaltyPointsMovementSummary($startLoyalty, $endLoyalty, $this->storeA->id);

        $this->assertEquals(3, $loyalty['total_transactions']);
        $this->assertEquals(1, $loyalty['active_members']);
        // Earned: 100 + 50 = 150.00
        $this->assertEquals('150.00', $loyalty['points_earned']);
        // Redeemed: 30.00
        $this->assertEquals('30.00', $loyalty['points_redeemed']);
        // Net: 100 + 50 - 30 = 120.00
        $this->assertEquals('120.00', $loyalty['net_points_change']);
    }
}
