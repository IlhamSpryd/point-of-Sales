<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Finance\DataTransferObjects\JournalLineDto;
use App\Modules\Finance\Enums\AccountSubtypeEnum;
use App\Modules\Finance\Enums\AccountTypeEnum;
use App\Modules\Finance\Enums\JournalTypeEnum;
use App\Modules\Finance\Enums\NormalBalanceEnum;
use App\Modules\Finance\Models\AccountingPeriod;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\Currency;
use App\Modules\Finance\Models\ExchangeRate;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Services\CurrencyExchangeService;
use App\Modules\Finance\Services\JournalEntryService;
use App\Services\Context\TenantContext;
use Illuminate\Support\Str;
use Tests\TestCase;

class MultiCurrencyPostingTest extends TestCase
{
    protected bool $optOutFromDefaultTenant = true;

    protected Tenant $tenantA;
    protected Tenant $tenantB;
    protected User $userA;
    protected AccountingPeriod $periodA;
    protected ChartOfAccount $accountCash;
    protected ChartOfAccount $accountSales;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        app(TenantContext::class)->runWithoutTenant(function () {
            // Tenant A
            $this->tenantA = Tenant::create([
                'name' => 'Multi-Currency Tenant A',
                'slug' => 'fx-tenant-a-'.uniqid(),
                'status' => 'active',
                'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
                'access_status' => Tenant::ACCESS_ACTIVE,
            ]);

            // Tenant B
            $this->tenantB = Tenant::create([
                'name' => 'Multi-Currency Tenant B',
                'slug' => 'fx-tenant-b-'.uniqid(),
                'status' => 'active',
                'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
                'access_status' => Tenant::ACCESS_ACTIVE,
            ]);

            $this->userA = User::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Accountant A',
                'email' => 'accountant.fx.'.uniqid().'@apeiron.test',
                'password' => bcrypt('password'),
                'role' => 'ACCOUNTANT',
                'is_active' => true,
            ]);

            // Periode Akuntansi Aktif Tenant A
            $this->periodA = AccountingPeriod::create([
                'tenant_id' => $this->tenantA->id,
                'period_code' => '2026-11-FX',
                'period_number' => 11,
                'fiscal_year' => 2026,
                'start_date' => '2026-11-01',
                'end_date' => '2026-11-30',
                'status' => 'OPEN',
                'is_adjustment_period' => false,
            ]);

            // COA Kas USD
            $this->accountCash = ChartOfAccount::create([
                'tenant_id' => $this->tenantA->id,
                'account_code' => '1010-USD-'.uniqid(),
                'account_name' => 'Kas Valas USD',
                'account_type' => AccountTypeEnum::ASSET,
                'account_subtype' => AccountSubtypeEnum::CURRENT_ASSET,
                'normal_balance' => NormalBalanceEnum::DEBIT,
                'is_active' => true,
                'is_postable' => true,
                'allow_manual_journal' => true,
            ]);

            // COA Pendapatan Ekspor
            $this->accountSales = ChartOfAccount::create([
                'tenant_id' => $this->tenantA->id,
                'account_code' => '4010-EXP-'.uniqid(),
                'account_name' => 'Pendapatan Ekspor Valas',
                'account_type' => AccountTypeEnum::REVENUE,
                'account_subtype' => AccountSubtypeEnum::SALES_REVENUE,
                'normal_balance' => NormalBalanceEnum::CREDIT,
                'is_active' => true,
                'is_postable' => true,
                'allow_manual_journal' => true,
            ]);
        });

        app(TenantContext::class)->setTenantId($this->tenantA->id);
    }

    /**
     * Test 1: Master data mata uang dan kurs valas terisolasi ketat per tenant (Fail-closed isolation).
     */
    public function test_currency_and_exchange_rate_crud_with_multi_tenancy_isolation(): void
    {
        $context = app(TenantContext::class);

        // Buat mata uang di Tenant A
        $context->setTenantId($this->tenantA->id);
        $currIdr = Currency::create([
            'tenant_id' => $this->tenantA->id,
            'code' => 'IDR',
            'name' => 'Indonesian Rupiah',
            'symbol' => 'Rp',
            'is_base' => true,
        ]);

        $currUsd = Currency::create([
            'tenant_id' => $this->tenantA->id,
            'code' => 'USD',
            'name' => 'US Dollar',
            'symbol' => '$',
            'is_base' => false,
        ]);

        // Buat kurs di Tenant A
        $rateA = ExchangeRate::create([
            'tenant_id' => $this->tenantA->id,
            'from_currency' => 'USD',
            'to_currency' => 'IDR',
            'rate' => '15650.000000',
            'inverse_rate' => '0.000064',
            'effective_date' => now()->toDateString(),
            'rate_source' => 'BANK_INDONESIA',
        ]);

        // Verifikasi isolasi query dari Tenant A
        $this->assertCount(2, Currency::all());
        $this->assertCount(1, ExchangeRate::all());

        // Switch ke Tenant B
        $context->setTenantId($this->tenantB->id);
        $this->assertCount(0, Currency::all());
        $this->assertCount(0, ExchangeRate::all());
        $this->assertNull(Currency::find($currIdr->id));
        $this->assertNull(ExchangeRate::find($rateA->id));
    }

    /**
     * Test 2: Konversi valas presisi tinggi menggunakan BCMath.
     */
    public function test_currency_conversion_uses_exact_bcmath_precision(): void
    {
        /** @var CurrencyExchangeService $fxService */
        $fxService = app(CurrencyExchangeService::class);

        $fxService->setExchangeRate(
            tenantId: $this->tenantA->id,
            fromCurrency: 'USD',
            toCurrency: 'IDR',
            rate: '15650.000000'
        );

        // 125.50 USD * 15650 IDR = 1,964,075.00 IDR
        $idrAmount = $fxService->convert(
            amount: '125.50',
            fromCurrency: 'USD',
            toCurrency: 'IDR',
            tenantId: $this->tenantA->id
        );

        $this->assertEquals('1964075.00', $idrAmount);

        // Uji konversi mata uang identik (faktor 1.0)
        $sameAmount = $fxService->convert(
            amount: '500000.00',
            fromCurrency: 'IDR',
            toCurrency: 'IDR',
            tenantId: $this->tenantA->id
        );
        $this->assertEquals('500000.00', $sameAmount);
    }

    /**
     * Test 3: Perhitungan selisih kurs (Forex Gain / Loss) secara presisi.
     */
    public function test_forex_gain_loss_calculation(): void
    {
        /** @var CurrencyExchangeService $fxService */
        $fxService = app(CurrencyExchangeService::class);

        // Skenario 1: Kurs pelunasan lebih tinggi (Untung Selisih Kurs / FOREX GAIN)
        // 1000 USD @ 15.500 (Booking) vs @ 15.700 (Settlement) -> Selisih +200.000 IDR
        $gainResult = $fxService->calculateForexGainLoss(
            foreignAmount: '1000.00',
            bookingRate: '15500.000000',
            settlementRate: '15700.000000'
        );

        $this->assertEquals('GAIN', $gainResult['type']);
        $this->assertEquals('200000.00', $gainResult['amount']);
        $this->assertEquals('15500000.00', $gainResult['booking_idr']);
        $this->assertEquals('15700000.00', $gainResult['settlement_idr']);

        // Skenario 2: Kurs pelunasan lebih rendah (Rugi Selisih Kurs / FOREX LOSS)
        // 1000 USD @ 15.500 (Booking) vs @ 15.300 (Settlement) -> Selisih -200.000 IDR
        $lossResult = $fxService->calculateForexGainLoss(
            foreignAmount: '1000.00',
            bookingRate: '15500.000000',
            settlementRate: '15300.000000'
        );

        $this->assertEquals('LOSS', $lossResult['type']);
        $this->assertEquals('200000.00', $lossResult['amount']);

        // Skenario 3: Kurs identik (NONE)
        $noneResult = $fxService->calculateForexGainLoss(
            foreignAmount: '1000.00',
            bookingRate: '15500.000000',
            settlementRate: '15500.000000'
        );

        $this->assertEquals('NONE', $noneResult['type']);
        $this->assertEquals('0.00', $noneResult['amount']);
    }

    /**
     * Test 4: Sinkronisasi kurs tengah Bank Indonesia harian regional.
     */
    public function test_sync_bank_indonesia_rates_creates_exchange_rate_records(): void
    {
        /** @var CurrencyExchangeService $fxService */
        $fxService = app(CurrencyExchangeService::class);

        $count = $fxService->syncBankIndonesiaRates(
            tenantId: $this->tenantA->id,
            mockRates: [
                'USD' => '15600.000000',
                'SGD' => '11500.000000',
                'MYR' => '3500.000000',
            ]
        );

        $this->assertEquals(3, $count);

        $rateUsd = $fxService->getExchangeRate($this->tenantA->id, 'USD', 'IDR');
        $this->assertEquals('15600.000000', $rateUsd);

        $rateSgd = $fxService->getExchangeRate($this->tenantA->id, 'SGD', 'IDR');
        $this->assertEquals('11500.000000', $rateSgd);
    }

    /**
     * Test 5: Posting jurnal valas multi-currency mencatat nilai fungsional IDR dan nilai valuta asing pada baris buku besar.
     */
    public function test_multi_currency_journal_posting_preserves_functional_and_foreign_amounts(): void
    {
        /** @var JournalEntryService $journalService */
        $journalService = app(JournalEntryService::class);

        // Transaksi penjualan ekspor: 100.00 USD @ kurs 15.500 = Rp 1.550.000
        $lines = [
            new JournalLineDto(
                accountId: $this->accountCash->id,
                debitAmount: '1550000.00',
                creditAmount: '0.00',
                description: 'Penerimaan Kas Ekspor USD',
                foreignCurrencyCode: 'USD',
                foreignDebitAmount: '100.00',
                foreignCreditAmount: null,
                exchangeRate: '15500.000000'
            ),
            new JournalLineDto(
                accountId: $this->accountSales->id,
                debitAmount: '0.00',
                creditAmount: '1550000.00',
                description: 'Pendapatan Ekspor USD',
                foreignCurrencyCode: 'USD',
                foreignDebitAmount: null,
                foreignCreditAmount: '100.00',
                exchangeRate: '15500.000000'
            ),
        ];

        $journal = $journalService->createAndPostJournal(
            tenantId: $this->tenantA->id,
            storeId: null,
            postingDate: '2026-11-15',
            idempotencyKey: 'fx-journal-'.Str::random(8),
            lines: $lines,
            sourceModule: 'SALES',
            sourceType: 'EXPORT_INVOICE',
            journalType: JournalTypeEnum::SYSTEM_AUTO,
            notes: 'Jurnal Transaksi Valuta Asing USD',
            currencyCode: 'USD',
            actor: $this->userA
        );

        $this->assertInstanceOf(JournalEntry::class, $journal);
        $this->assertEquals('1550000.00', $journal->total_debit);
        $this->assertEquals('1550000.00', $journal->total_credit);
        $this->assertEquals('USD', $journal->currency_code);

        // Verifikasi rincian baris jurnal di database
        $savedLines = $journal->lines()->orderBy('line_number')->get();
        $this->assertCount(2, $savedLines);

        $debitLine = $savedLines->first();
        $this->assertEquals('1550000.00', $debitLine->debit_amount);
        $this->assertEquals('USD', $debitLine->foreign_currency_code);
        $this->assertEquals('100.00', $debitLine->foreign_debit_amount);
        $this->assertEquals('15500.000000', $debitLine->exchange_rate);

        $creditLine = $savedLines->last();
        $this->assertEquals('1550000.00', $creditLine->credit_amount);
        $this->assertEquals('USD', $creditLine->foreign_currency_code);
        $this->assertEquals('100.00', $creditLine->foreign_credit_amount);
        $this->assertEquals('15500.000000', $creditLine->exchange_rate);
    }
}
