<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Models\AccountingPeriod;
use App\Models\Category;
use App\Models\Company;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Finance\Enums\AccountSubtypeEnum;
use App\Modules\Finance\Enums\AccountTypeEnum;
use App\Modules\Finance\Enums\JournalStatusEnum;
use App\Modules\Finance\Enums\NormalBalanceEnum;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Services\ForexGainLossPostingService;
use App\Modules\Finance\Services\RegionalTaxEngineService;
use App\Modules\Reports\Services\AiBusinessAssistantService;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RegionalAndAiExpansionFase4Test extends TestCase
{
    use DatabaseTransactions;

    protected Tenant $tenant;
    protected Company $company;
    protected Store $store;
    protected User $apiUser;
    protected AccountingPeriod $period;
    protected ChartOfAccount $accountBank;
    protected ChartOfAccount $accountAr;
    protected ChartOfAccount $accountAp;

    protected function afterSubclassSetUp(): void
    {
        $this->tenant = $this->defaultTenant;
        $this->store = $this->defaultStore;

        $this->company = Company::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'company_code' => 'CMP-FASE4'],
            [
                'company_name' => 'PT Apeiron Regional SEA',
                'legal_name' => 'PT Apeiron Regional Asia Tenggara',
                'npwp' => '012345678901234',
                'is_active' => true,
                'is_default' => true,
            ]
        );

        $roleManager = Role::firstOrCreate(
            ['name' => 'Manager'],
            ['description' => 'Store Manager', 'is_admin' => false]
        );

        $this->apiUser = User::where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->apiUser->update([
            'role_id' => $roleManager->id,
            'store_id' => $this->store->id,
            'email_verified_at' => now(),
            'onboarding_completed' => true,
        ]);
        $this->apiUser->stores()->sync([
            $this->store->id => ['tenant_id' => $this->tenant->id],
        ]);

        // Periode Akuntansi Aktif
        $this->period = AccountingPeriod::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'period_code' => 'P4-' . rand(10000, 99999),
            'period_number' => (int) date('m'),
            'fiscal_year' => (int) date('Y'),
            'start_date' => Carbon::now()->startOfMonth()->toDateString(),
            'end_date' => Carbon::now()->endOfMonth()->toDateString(),
            'status' => 'OPEN',
            'is_adjustment_period' => false,
        ]);

        // Akun Bank
        $this->accountBank = ChartOfAccount::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'account_code' => '1101-' . Str::random(4),
            'account_name' => 'Kas & Bank Operasional',
            'account_type' => AccountTypeEnum::ASSET,
            'account_subtype' => AccountSubtypeEnum::CASH_BANK,
            'normal_balance' => NormalBalanceEnum::DEBIT,
            'level' => 3,
            'is_active' => true,
            'is_postable' => true,
            'allow_manual_journal' => true,
        ]);

        // Akun Piutang Usaha (AR)
        $this->accountAr = ChartOfAccount::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'account_code' => '1130-' . Str::random(4),
            'account_name' => 'Piutang Usaha Dagang (AR)',
            'account_type' => AccountTypeEnum::ASSET,
            'account_subtype' => AccountSubtypeEnum::RECEIVABLE,
            'normal_balance' => NormalBalanceEnum::DEBIT,
            'level' => 3,
            'is_active' => true,
            'is_postable' => true,
            'allow_manual_journal' => true,
        ]);

        // Akun Hutang Usaha (AP)
        $this->accountAp = ChartOfAccount::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'account_code' => '2101-' . Str::random(4),
            'account_name' => 'Hutang Usaha Dagang (AP)',
            'account_type' => AccountTypeEnum::LIABILITY,
            'account_subtype' => AccountSubtypeEnum::PAYABLE,
            'normal_balance' => NormalBalanceEnum::CREDIT,
            'level' => 3,
            'is_active' => true,
            'is_postable' => true,
            'allow_manual_journal' => true,
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(TenantContext::class)->setStoreId($this->store->id);
    }

    /**
     * Slice 1: Multi-Currency Realized Forex Gain Posting on AR Settlement.
     */
    public function test_multi_currency_forex_gain_posting_on_ar_settlement(): void
    {
        /** @var ForexGainLossPostingService $service */
        $service = app(ForexGainLossPostingService::class);

        // 100 USD @ booking rate 15,000 IDR (1,500,000 IDR)
        // Dilunasi @ settlement rate 15,600 IDR (1,560,000 IDR) -> Gain 60,000 IDR
        $journal = $service->postRealizedForexJournal(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            date: now()->toDateString(),
            foreignCurrency: 'USD',
            foreignAmount: '100.00',
            bookingRate: '15000.000000',
            settlementRate: '15600.000000',
            bankAccountId: $this->accountBank->id,
            arOrApAccountId: $this->accountAr->id,
            isReceivable: true,
            referenceNumber: 'INV-USD-2027-001',
            actor: $this->apiUser
        );

        $this->assertInstanceOf(JournalEntry::class, $journal);
        $this->assertEquals(JournalStatusEnum::POSTED, $journal->status);
        $this->assertEquals('1560000.00', $journal->total_debit);
        $this->assertEquals('1560000.00', $journal->total_credit);

        // Verifikasi rincian baris jurnal (3 baris: Bank Debit, AR Credit, Gain Credit)
        $lines = $journal->lines()->get();
        $this->assertCount(3, $lines);

        $bankLine = $lines->firstWhere('account_id', $this->accountBank->id);
        $this->assertNotNull($bankLine);
        $this->assertEquals('1560000.00', $bankLine->debit_amount);
        $this->assertEquals('USD', $bankLine->foreign_currency_code);
        $this->assertEquals('100.00', $bankLine->foreign_debit_amount);

        $arLine = $lines->firstWhere('account_id', $this->accountAr->id);
        $this->assertNotNull($arLine);
        $this->assertEquals('1500000.00', $arLine->credit_amount);

        $gainAccount = $service->resolveForexAccount($this->tenant->id, ForexGainLossPostingService::SYSTEM_KEY_REALIZED_GAIN);
        $gainLine = $lines->firstWhere('account_id', $gainAccount->id);
        $this->assertNotNull($gainLine);
        $this->assertEquals('60000.00', $gainLine->credit_amount);
    }

    /**
     * Slice 1: Multi-Currency Realized Forex Loss Posting on AP Settlement.
     */
    public function test_multi_currency_forex_loss_posting_on_ap_settlement(): void
    {
        /** @var ForexGainLossPostingService $service */
        $service = app(ForexGainLossPostingService::class);

        // 500 SGD @ booking rate 11,500 IDR (5,750,000 IDR)
        // Dibayar @ settlement rate 11,800 IDR (5,900,000 IDR) -> Loss 150,000 IDR
        $journal = $service->postRealizedForexJournal(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            date: now()->toDateString(),
            foreignCurrency: 'SGD',
            foreignAmount: '500.00',
            bookingRate: '11500.000000',
            settlementRate: '11800.000000',
            bankAccountId: $this->accountBank->id,
            arOrApAccountId: $this->accountAp->id,
            isReceivable: false,
            referenceNumber: 'BILL-SGD-2027-001',
            actor: $this->apiUser
        );

        $this->assertInstanceOf(JournalEntry::class, $journal);
        $this->assertEquals('5900000.00', $journal->total_debit);
        $this->assertEquals('5900000.00', $journal->total_credit);

        // Verifikasi rincian baris jurnal (3 baris: AP Debit, Loss Debit, Bank Credit)
        $lines = $journal->lines()->get();
        $this->assertCount(3, $lines);

        $apLine = $lines->firstWhere('account_id', $this->accountAp->id);
        $this->assertNotNull($apLine);
        $this->assertEquals('5750000.00', $apLine->debit_amount);

        $bankLine = $lines->firstWhere('account_id', $this->accountBank->id);
        $this->assertNotNull($bankLine);
        $this->assertEquals('5900000.00', $bankLine->credit_amount);

        $lossAccount = $service->resolveForexAccount($this->tenant->id, ForexGainLossPostingService::SYSTEM_KEY_REALIZED_LOSS);
        $lossLine = $lines->firstWhere('account_id', $lossAccount->id);
        $this->assertNotNull($lossLine);
        $this->assertEquals('150000.00', $lossLine->debit_amount);
    }

    /**
     * Slice 1: Unrealized Forex Revaluation Posting on Open Foreign Asset/Liability.
     */
    public function test_unrealized_forex_revaluation_posting(): void
    {
        /** @var ForexGainLossPostingService $service */
        $service = app(ForexGainLossPostingService::class);

        // Saldo piutang terbuka 1,000 USD bernilai buku Rp 15,000,000
        // Kurs penutupan akhir periode 15,400 IDR -> Nilai revaluasi Rp 15,400,000 -> Gain Rp 400,000
        $journal = $service->postUnrealizedRevaluationJournal(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            periodEndDate: now()->toDateString(),
            foreignCurrency: 'USD',
            foreignBalance: '1000.00',
            bookValueBase: '15000000.00',
            closingRate: '15400.000000',
            assetOrLiabilityAccountId: $this->accountAr->id,
            isAsset: true,
            reference: 'REVAL-2027-USD-01',
            actor: $this->apiUser
        );

        $this->assertInstanceOf(JournalEntry::class, $journal);
        $this->assertEquals('400000.00', $journal->total_debit);
        $this->assertEquals('400000.00', $journal->total_credit);

        // Verifikasi AR bertambah Rp 400,000 dan Unrealized Gain bertambah Rp 400,000
        $lines = $journal->lines()->get();
        $this->assertCount(2, $lines);

        $arLine = $lines->firstWhere('account_id', $this->accountAr->id);
        $this->assertNotNull($arLine);
        $this->assertEquals('400000.00', $arLine->debit_amount);

        $gainLine = $lines->firstWhere('debit_amount', '0.00');
        $this->assertNotNull($gainLine);
        $this->assertEquals('400000.00', $gainLine->credit_amount);
    }

    /**
     * Slice 2: Southeast Asia Regional Tax Localization (ID, MY, SG, PH) Engine & Calculations.
     */
    public function test_regional_tax_engine_validation_and_calculations(): void
    {
        /** @var RegionalTaxEngineService $taxEngine */
        $taxEngine = app(RegionalTaxEngineService::class);

        // 1. Validasi Tax ID Regional
        $this->assertTrue($taxEngine->validateTaxId('ID', '012345678901234')); // NPWP ID 15 digit
        $this->assertTrue($taxEngine->validateTaxId('ID', '0123456789012345')); // NIK ID 16 digit
        $this->assertTrue($taxEngine->validateTaxId('MY', 'W10-1808-32000000')); // SST Malaysia
        $this->assertTrue($taxEngine->validateTaxId('SG', '201812345M')); // GST Singapura
        $this->assertTrue($taxEngine->validateTaxId('PH', '123-456-789-000')); // BIR TIN Filipina

        $this->assertFalse($taxEngine->validateTaxId('SG', 'INVALID_123'));
        $this->assertFalse($taxEngine->validateTaxId('PH', '123'));

        // 2. Perhitungan Pajak Malaysia SST
        $mySalesTax = $taxEngine->calculateRegionalTax('MY', 'SST_SALES', '1000.00');
        $this->assertEquals('100.00', $mySalesTax['tax_amount']);
        $this->assertEquals('1100.00', $mySalesTax['total_with_tax']);

        $myServiceTax = $taxEngine->calculateRegionalTax('MY', 'SST_SERVICE', '500.00');
        $this->assertEquals('40.00', $myServiceTax['tax_amount']); // 8% dari 500 = 40.00
        $this->assertEquals('540.00', $myServiceTax['total_with_tax']);

        // 3. Perhitungan Pajak Singapura GST (9%)
        $sgGst = $taxEngine->calculateRegionalTax('SG', 'GST_SG', '200.00');
        $this->assertEquals('18.00', $sgGst['tax_amount']); // 9% dari 200 = 18.00
        $this->assertEquals('218.00', $sgGst['total_with_tax']);

        // 4. Perhitungan Pajak Filipina BIR VAT (12%)
        $phVat = $taxEngine->calculateRegionalTax('PH', 'VAT_PH', '1000.00');
        $this->assertEquals('120.00', $phVat['tax_amount']);
        $this->assertEquals('1120.00', $phVat['total_with_tax']);
    }

    /**
     * Slice 2: Regional Tax Statutory Return Summaries (SST-02, GST F5, BIR 2550).
     */
    public function test_regional_tax_statutory_return_summaries(): void
    {
        /** @var RegionalTaxEngineService $taxEngine */
        $taxEngine = app(RegionalTaxEngineService::class);

        // 1. Malaysia SST-02 Return
        $sstSummary = $taxEngine->prepareMalaysiaSst02Summary([
            ['type' => 'sales', 'amount' => '10000.00', 'rate' => '10.00'],
            ['type' => 'service', 'amount' => '5000.00', 'rate' => '8.00'],
            ['type' => 'exempt', 'amount' => '2000.00'],
        ]);
        $this->assertEquals('17000.00', $sstSummary['total_sales_value']);
        $this->assertEquals('1000.00', $sstSummary['sales_tax_payable']);
        $this->assertEquals('400.00', $sstSummary['service_tax_payable']);
        $this->assertEquals('1400.00', $sstSummary['total_sst_payable']);

        // 2. Singapore GST F5 Return
        $gstSummary = $taxEngine->prepareSingaporeGstF5Summary([
            ['type' => 'standard', 'amount' => '50000.00'],
            ['type' => 'zero_rated', 'amount' => '20000.00'],
            ['type' => 'exempt', 'amount' => '5000.00'],
        ]);
        $this->assertEquals('50000.00', $gstSummary['box_1_standard_rated_supplies']);
        $this->assertEquals('20000.00', $gstSummary['box_2_zero_rated_supplies']);
        $this->assertEquals('75000.00', $gstSummary['box_4_total_supplies']);
        $this->assertEquals('4500.00', $gstSummary['box_6_output_tax_due']); // 9% dari 50000 = 4500

        // 3. Philippines BIR Form 2550 Return
        $birSummary = $taxEngine->preparePhilippinesBir2550Summary([
            ['type' => 'vatable', 'amount' => '100000.00'],
            ['type' => 'exempt', 'amount' => '15000.00'],
            ['type' => 'zero_rated', 'amount' => '10000.00'],
        ]);
        $this->assertEquals('100000.00', $birSummary['vatable_sales']);
        $this->assertEquals('125000.00', $birSummary['total_sales_php']);
        $this->assertEquals('12000.00', $birSummary['output_vat_due']); // 12% dari 100000 = 12000
    }

    /**
     * Slice 3: Controlled AI Business Assistant (Human-in-the-Loop & Read-Only Insights).
     */
    public function test_ai_business_assistant_sales_and_inventory_analysis(): void
    {
        // Siapkan data pesanan penjualan
        Order::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->apiUser->id,
            'order_code' => 'ORD-AI-001',
            'idempotency_key' => (string) Str::uuid(),
            'order_date' => Carbon::now()->subDays(2)->toDateString(),
            'order_type' => 'retail',
            'order_status' => 'paid',
            'subtotal_amount' => 500000.00,
            'order_amount' => 500000.00,
        ]);

        $category = Category::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'AI Test Category',
            'category_code' => 'CAT-AI-01',
            'is_active' => true,
        ]);

        $criticalProduct = Product::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_name' => 'Critical Fast-Moving Item',
            'product_code' => 'PRD-CRIT-01',
            'barcode' => '899999900001',
            'base_price' => 25000.00,
            'track_inventory' => true,
            'is_active' => true,
        ]);

        // Simulasikan stok menipis (quantity = 2)
        DB::table('product_stock_balances')->updateOrInsert(
            [
                'tenant_id' => $this->tenant->id,
                'store_id' => $this->store->id,
                'product_id' => $criticalProduct->id,
            ],
            [
                'quantity' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        /** @var AiBusinessAssistantService $aiService */
        $aiService = app(AiBusinessAssistantService::class);

        // 1. Analisis Tren Penjualan
        $trends = $aiService->analyzeSalesTrends($this->tenant->id, $this->store->id);
        $this->assertEquals('500000.00', $trends['current_period_revenue']);
        $this->assertEquals(1, $trends['total_orders_count']);

        // 2. Deteksi Anomali Persediaan
        $anomalies = $aiService->detectInventoryAnomalies($this->tenant->id, $this->store->id);
        $this->assertGreaterThanOrEqual(1, $anomalies['critical_low_stock_count']);
        $this->assertEquals('PRD-CRIT-01', $anomalies['critical_low_items'][0]['product_code']);

        // 3. Verifikasi Prinsip Human-in-the-Loop pada Rekomendasi
        $recommendations = $aiService->generateActionableRecommendations($this->tenant->id, $this->store->id);
        $this->assertNotEmpty($recommendations);

        foreach ($recommendations as $rec) {
            $this->assertEquals('draft_recommendation', $rec['status']);
            $this->assertTrue($rec['requires_human_approval']);
            $this->assertNotEmpty($rec['expected_impact']);
        }

        // 4. Tanya Jawab Bahasa Alami
        $chat = $aiService->askAssistant($this->tenant->id, 'Bagaimana kondisi persediaan dan stok kritis kami saat ini?', $this->store->id);
        $this->assertEquals('INVENTORY_HEALTH', $chat['intent']);
        $this->assertStringContainsString('stok kritis', $chat['summary']);
        $this->assertStringContainsString('Read-Only', $chat['compliance_note']);
    }

    /**
     * Slice 4: Scoped Sanctum REST API for AI Business Assistant Endpoints.
     */
    public function test_scoped_sanctum_api_ai_assistant_endpoints(): void
    {
        Sanctum::actingAs($this->apiUser, ['analytics:read']);

        // 1. GET /api/v1/ai/insights/sales-trends
        $trendsResp = $this->withHeaders([
            'Accept' => 'application/json',
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson('/api/v1/ai/insights/sales-trends');

        $trendsResp->assertStatus(200);
        $trendsResp->assertJsonStructure([
            'status',
            'data' => [
                'current_period_revenue',
                'trend_direction',
                'total_orders_count',
            ],
        ]);

        // 2. GET /api/v1/ai/insights/inventory-anomalies
        $invResp = $this->withHeaders([
            'Accept' => 'application/json',
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson('/api/v1/ai/insights/inventory-anomalies');

        $invResp->assertStatus(200);
        $invResp->assertJsonStructure([
            'status',
            'data' => [
                'critical_low_stock_count',
                'dead_stock_count',
            ],
        ]);

        // 3. GET /api/v1/ai/recommendations
        $recResp = $this->withHeaders([
            'Accept' => 'application/json',
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson('/api/v1/ai/recommendations');

        $recResp->assertStatus(200);
        $recResp->assertJson(['status' => 'success']);

        // 4. POST /api/v1/ai/query
        $queryResp = $this->withHeaders([
            'Accept' => 'application/json',
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson('/api/v1/ai/query', [
            'query' => 'Berikan rangkuman tren penjualan minggu ini',
        ]);

        $queryResp->assertStatus(200);
        $queryResp->assertJson([
            'status' => 'success',
            'data' => [
                'intent' => 'SALES_PERFORMANCE',
            ],
        ]);

        // 5. Penolakan Token Tanpa Scope 'analytics:read'
        Sanctum::actingAs($this->apiUser, ['orders:read']); // wrong scope

        $forbiddenResp = $this->withHeaders([
            'Accept' => 'application/json',
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson('/api/v1/ai/insights/sales-trends');

        $forbiddenResp->assertStatus(403);
        $this->assertStringContainsString('analytics:read', (string) $forbiddenResp->json('message'));
    }
}
