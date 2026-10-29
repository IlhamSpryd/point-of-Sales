<?php

namespace Tests\Feature\Finance;

use App\Models\AccountingPeriod;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use App\Modules\Finance\Enums\AccountingPeriodStatusEnum;
use App\Modules\Finance\Enums\JournalStatusEnum;
use App\Modules\Finance\Models\FinancialEventOutbox;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Services\PostingEngineService;
use App\Modules\Sales\Services\TransactionService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DefaultPostingRulesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosSaleAndCogsPostingTest extends TestCase
{
    use RefreshDatabase;

    protected $tenant;

    protected $store;

    protected User $cashier;

    protected Shift $shift;

    protected AccountingPeriod $period;

    protected Product $product;

    protected TransactionService $transactionService;

    protected PostingEngineService $postingEngine;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = $this->defaultTenant;
        $this->store = $this->defaultStore;

        // Seed Chart of Accounts dan Default Posting Rules
        app(ChartOfAccountsSeeder::class)->seedForTenant($this->tenant->id);
        app(DefaultPostingRulesSeeder::class)->seedForTenant($this->tenant->id);

        $this->transactionService = app(TransactionService::class);
        $this->postingEngine = app(PostingEngineService::class);

        // Buat open accounting period
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

        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);

        // Buat shift terbuka
        $this->shift = Shift::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->cashier->id,
            'active_cashier_id' => $this->cashier->id,
            'starting_balance' => 100000,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        // Buat produk retail dengan standard cost
        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_price' => 50000,
            'standard_cost' => 30000,
            'stock' => 100,
        ]);
    }

    public function test_pos_checkout_records_financial_outbox_and_posts_balanced_gl_journal(): void
    {
        $payload = [
            'store_id' => $this->store->id,
            'shift_id' => $this->shift->id,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 2,
                    'unit_price' => 50000,
                ],
            ],
            'payments' => [
                [
                    'method' => 'cash',
                    'amount' => 111000,
                    'amount_tendered' => 111000,
                ],
            ],
            'idempotency_key' => 'POS-CHECKOUT-TEST-001',
        ];

        // Eksekusi transaksi POS checkout
        $order = $this->transactionService->createTransaction($payload, $this->cashier->id);

        $this->assertNotNull($order);
        $this->assertEquals('paid', $order->order_status->value ?? $order->order_status);

        // 1. Verifikasi record FinancialEventOutbox terbentuk
        $outbox = FinancialEventOutbox::where('tenant_id', $this->tenant->id)
            ->where('event_type', 'pos.order.paid')
            ->where('source_id', $order->id)
            ->first();

        $this->assertNotNull($outbox, 'FinancialEventOutbox untuk pos.order.paid wajib terbentuk');
        $this->assertEquals('PROCESSED', $outbox->status->value ?? $outbox->status);
        $this->assertEquals('111000.00', $outbox->payload['total_amount']);
        $this->assertEquals('11000.00', $outbox->payload['tax_amount']);
        $this->assertEquals('100000.00', $outbox->payload['subtotal']);

        // 2. Verifikasi jurnal yang terbentuk otomatis via ProcessFinancialOutboxJob
        $journal = JournalEntry::find($outbox->journal_entry_id);
        $this->assertNotNull($journal);
        $this->assertEquals(JournalStatusEnum::POSTED->value, $journal->status->value);

        // Uji idempotensi: memproses ulang outbox yang sama mengembalikan jurnal yang sama tanpa duplikasi
        $idempotentJournal = $this->postingEngine->processOutboxEvent($outbox);
        $this->assertEquals($journal->id, $idempotentJournal->id);

        // 3. Verifikasi Invarian FIN-M7-01: Debit == Credit
        $this->assertEquals($journal->total_debit, $journal->total_credit);
        $this->assertTrue(bccomp($journal->total_debit, '0.00', 2) > 0);

        // 4. Verifikasi baris jurnal: Kas (Debit 111.000) vs Penjualan (Credit 100.000) + PPN (Credit 11.000)
        // dan COGS (Debit 60.000) vs Persediaan (Credit 60.000)
        $lines = $journal->lines;
        $this->assertGreaterThanOrEqual(4, $lines->count());

        $cashLine = $lines->first(fn ($l) => $l->account->system_account_key === 'CASH_MAIN');
        $this->assertNotNull($cashLine);
        $this->assertEquals('111000.00', $cashLine->debit_amount);

        $taxLine = $lines->first(fn ($l) => $l->account->system_account_key === 'TAX_OUTPUT_PPN');
        $this->assertNotNull($taxLine);
        $this->assertEquals('11000.00', $taxLine->credit_amount);

        $salesLine = $lines->first(fn ($l) => $l->account->system_account_key === 'SALES_RETAIL');
        $this->assertNotNull($salesLine);
        $this->assertEquals('100000.00', $salesLine->credit_amount);

        $cogsLine = $lines->first(fn ($l) => $l->account->system_account_key === 'COGS_RETAIL');
        $this->assertNotNull($cogsLine);
        $this->assertEquals('60000.00', $cogsLine->debit_amount);

        $invLine = $lines->first(fn ($l) => $l->account->system_account_key === 'INVENTORY_RETAIL_PRODUCT');
        $this->assertNotNull($invLine);
        $this->assertEquals('60000.00', $invLine->credit_amount);
    }
}
