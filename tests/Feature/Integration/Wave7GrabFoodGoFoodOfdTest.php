<?php

declare(strict_types=1);

namespace Tests\Feature\Integration;

use App\Core\Context\TenantContext;
use App\Enums\OrderStatus;
use App\Jobs\ProcessWebhookOrderJob;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Finance\Services\FinancialOutboxDispatcherService;
use App\Modules\Finance\Services\PostingEngineService;
use App\Modules\Integration\Models\OfdChannelConfig;
use App\Modules\Organization\Models\Role;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Models\Tenant;
use App\Modules\Organization\Models\User;
use App\Modules\Organization\Services\RoleService;
use App\Modules\Sales\Models\ChannelOrderLog;
use App\Modules\Sales\Models\ChannelProductMapping;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\TransactionService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DefaultPostingRulesSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Wave 7 Checkpoint 7C Verification:
 * GrabFood & GoFood Omnichannel OFD Adapter (HMAC Webhooks, Balanced PSAK GL & Assisted Mode).
 */
class Wave7GrabFoodGoFoodOfdTest extends TestCase
{
    use DatabaseTransactions;

    protected bool $optOutFromDefaultTenant = true;

    protected Tenant $tenant;

    protected Store $store;

    protected User $cashier;

    protected Product $product;

    protected OfdChannelConfig $grabConfig;

    protected string $signingSecret = 'test_secret_key_12345';

    protected function afterSubclassSetUp(): void
    {
        $this->tenant = Tenant::create([
            'name' => 'OFD Kitchen Bistro '.uniqid(),
            'code' => 'OFD-'.strtoupper(Str::random(6)),
            'subdomain' => 'ofd-'.strtolower(Str::random(6)),
            'primary_industry' => 'fnb',
            'business_subtype' => 'restaurant',
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
        ]);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Kebayoran Hub',
            'code' => 'STR-OFD-1',
            'store_vertical' => 'fnb',
            'default_channel' => 'dine_in',
            'is_active' => true,
        ]);

        $context = app(TenantContext::class);
        $context->setTenantId($this->tenant->id);
        $context->setStoreId($this->store->id);

        app(RoleService::class)->provisionDefaultRoles($this->tenant);
        $cashierRole = Role::where('tenant_id', $this->tenant->id)->where('name', 'Kasir')->first()
            ?? Role::where('tenant_id', $this->tenant->id)->where('name', 'Owner')->firstOrFail();

        $this->cashier = User::create([
            'name' => 'Kasir Siti',
            'email' => 'siti.'.uniqid().'@bistro.test',
            'password' => bcrypt('Secret123!'),
            'role_id' => $cashierRole->id,
            'tenant_id' => $this->tenant->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        User::create([
            'name' => 'Channel Order System',
            'email' => \App\Modules\Organization\Services\SystemUserResolver::channelEmail((int) $this->tenant->id),
            'password' => bcrypt('SystemPass123!'),
            'role_id' => $cashierRole->id,
            'tenant_id' => $this->tenant->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        // Seed COA & Posting Rules for tenant
        app(ChartOfAccountsSeeder::class)->seedForTenant((int) $this->tenant->id);
        app(DefaultPostingRulesSeeder::class)->seedForTenant((int) $this->tenant->id);

        \App\Modules\Finance\Models\AccountingPeriod::create([
            'tenant_id' => $this->tenant->id,
            'period_code' => now()->format('Y-m'),
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
            'fiscal_year' => (int) now()->format('Y'),
            'period_number' => (int) now()->format('n'),
            'status' => 'OPEN',
            'is_adjustment_period' => false,
        ]);

        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'name' => 'Beverages',
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'category_id' => $category->id,
            'name' => 'Kopi Susu Gula Aren 500ml',
            'product_price' => 30000,
            'price' => 30000,
            'sku' => 'KPS-001',
            'track_inventory' => false,
            'is_active' => true,
        ]);

        ChannelProductMapping::create([
            'tenant_id' => $this->tenant->id,
            'provider' => 'grabfood',
            'external_product_id' => 'GRAB-SKU-001',
            'product_id' => $this->product->id,
        ]);

        ChannelProductMapping::create([
            'tenant_id' => $this->tenant->id,
            'provider' => 'gofood',
            'external_product_id' => 'GO-SKU-001',
            'product_id' => $this->product->id,
        ]);

        $this->grabConfig = OfdChannelConfig::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'provider' => 'grabfood',
            'merchant_id' => 'MERCHANT-GF-888',
            'signing_secret' => $this->signingSecret,
            'commission_rate_pct' => 20.00,
            'price_markup_pct' => 0.00,
            'auto_accept_order' => true,
            'is_active' => true,
        ]);
    }

    /**
     * 1. Tier 2 HMAC Webhook: valid HMAC accepted (202), invalid or missing HMAC rejected (401).
     */
    public function test_grabfood_webhook_hmac_verification(): void
    {
        $payload = [
            'id' => 'GF-ORDER-1001',
            'store_id' => $this->store->id,
            'short_code' => 'GF-101',
            'items' => [
                ['id' => 'GRAB-SKU-001', 'name' => 'Kopi Susu Gula Aren', 'quantity' => 2],
            ],
            'customer' => ['name' => 'Andi Pratama', 'phone' => '081234567890'],
        ];

        $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);

        // Case A: Missing signature -> rejected 401
        $responseA = $this->call(
            'POST',
            '/api/webhooks/grabfood/orders?tenant_id='.$this->tenant->id.'&store_id='.$this->store->id,
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $rawBody
        );
        $responseA->assertStatus(401);

        // Case B: Invalid signature -> rejected 401
        $responseB = $this->call(
            'POST',
            '/api/webhooks/grabfood/orders?tenant_id='.$this->tenant->id.'&store_id='.$this->store->id,
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_GRAB_SIGNATURE' => 'invalid_signature_hash'],
            $rawBody
        );
        $responseB->assertStatus(401);

        // Case C: Valid HMAC-SHA256 signature -> accepted 202
        $validSignature = hash_hmac('sha256', $rawBody, $this->signingSecret);
        $responseC = $this->call(
            'POST',
            '/api/webhooks/grabfood/orders?tenant_id='.$this->tenant->id.'&store_id='.$this->store->id,
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_GRAB_SIGNATURE' => $validSignature],
            $rawBody
        );
        $responseC->assertStatus(202);

        $this->assertDatabaseHas('channel_order_logs', [
            'tenant_id' => $this->tenant->id,
            'provider' => 'grabfood',
            'external_order_id' => 'GF-ORDER-1001',
        ]);
    }

    /**
     * 2. ProcessWebhookOrderJob creates order with correct commission, promo subsidy & net payout.
     */
    public function test_process_webhook_order_job_and_financial_attributes(): void
    {
        $log = ChannelOrderLog::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'provider' => 'grabfood',
            'external_order_id' => 'GF-ORDER-2002',
            'payload' => [
                'order_id' => 'GF-ORDER-2002',
                'short_code' => 'GF-202',
                'items' => [
                    ['id' => 'GRAB-SKU-001', 'name' => 'Kopi Susu Gula Aren', 'quantity' => 2],
                ],
                'merchant_promo_subsidy' => 6000,
                'driver_name' => 'Pak Joko',
                'driver_phone' => '081299887766',
            ],
            'status' => 'pending',
        ]);

        $job = new ProcessWebhookOrderJob($log, (int) $this->tenant->id, (int) $this->store->id);
        $job->handle(app(TransactionService::class));

        $log->refresh();
        $this->assertSame('completed', $log->status);
        $this->assertNotNull($log->order_id);

        $order = Order::find($log->order_id);
        $this->assertNotNull($order);
        $this->assertSame('grabfood', $order->order_channel);
        $this->assertSame('GF-ORDER-2002', $order->external_order_id);
        $this->assertSame('GF-202', $order->external_short_code);
        $this->assertSame('Pak Joko', $order->driver_name);
        $this->assertSame('20.00', (string) $order->platform_commission_rate);
        // Subtotal = 2 * 30000 = 60000
        $this->assertSame(60000, (int) $order->subtotal_amount);
        // Commission = 20% of 60000 = 12000
        $this->assertSame('12000.00', (string) $order->platform_commission_amount);
        // Merchant Promo Subsidy = 6000
        $this->assertSame('6000.00', (string) $order->merchant_promo_subsidy);
        // Net Payout = 60000 + Tax(6600) - 6000 (promo) - 12000 (commission) = 48600.00
        $this->assertSame('48600.00', (string) $order->net_payout_amount);
    }

    /**
     * 3. Balanced PSAK GL Journal: ofd.order.completed & ofd.payout.settled balance exactly to Rp 0,00!
     */
    public function test_balanced_psak_gl_posting_and_payout_clearing_zero_variance(): void
    {
        // Create an OFD completed order: Subtotal 60,000, Tax 6,000, Merchant Promo 6,000, Commission 12,000
        $order = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->cashier->id,
            'order_code' => 'ORD-OFD-'.rand(100, 999),
            'idempotency_key' => (string) Str::uuid(),
            'order_date' => now()->toDateString(),
            'order_type' => 'takeaway',
            'order_channel' => 'grabfood',
            'origin_channel' => 'grabfood',
            'order_status' => OrderStatus::Paid,
            'payment_method' => 'ewallet',
            'subtotal_amount' => 60000,
            'tax_amount' => 6000,
            'order_amount' => 66000,
            'platform_commission_rate' => 20.00,
            'platform_commission_amount' => 12000.00,
            'merchant_promo_subsidy' => 6000.00,
            'net_payout_amount' => 48000.00, // (60000 + 6000 - 6000 - 12000) = 48000
        ]);

        $dispatcher = app(FinancialOutboxDispatcherService::class);
        $postingEngine = app(PostingEngineService::class);

        // Step 1 & 2: Dispatch ofd.order.completed event
        $outboxOrder = $dispatcher->dispatchOfdOrderCompleted(
            $order,
            commissionAmount: 12000.00,
            merchantPromoSubsidy: 6000.00
        );

        $this->assertNotNull($outboxOrder);
        $journal = $postingEngine->processOutboxEvent($outboxOrder);
        $this->assertNotNull($journal);

        // Verify Journal Lines:
        // Dr 1125 Piutang Kliring OFD = 60,000
        // Dr 4195 Subsidi Promo Merchant = 6,000
        // Cr 4100 Pendapatan Penjualan = 60,000
        // Cr 2200 Utang Pajak PPN/PB1 = 6,000
        // Dr 5200 Beban Komisi Platform = 12,000
        // Cr 1125 Piutang Kliring OFD = 12,000
        // Total Debit: 60,000 + 6,000 + 12,000 = 78,000
        // Total Credit: 60,000 + 6,000 + 12,000 = 78,000
        $totalDebit = DB::table('journal_lines')->where('journal_entry_id', $journal->id)->sum('debit_amount');
        $totalCredit = DB::table('journal_lines')->where('journal_entry_id', $journal->id)->sum('credit_amount');

        $this->assertSame('78000.00', number_format((float) $totalDebit, 2, '.', ''));
        $this->assertSame('78000.00', number_format((float) $totalCredit, 2, '.', ''));
        $this->assertSame((float) $totalDebit, (float) $totalCredit, 'Debit and Credit must balance with exact Rp 0,00 difference!');

        // Check Account 1125 balance:
        // Dr 60,000 - Cr 12,000 = +48,000 (Exactly equal to net_payout_amount!)
        $account1125 = DB::table('chart_of_accounts')
            ->where('tenant_id', $this->tenant->id)
            ->where('account_code', '1125')
            ->first();
        $this->assertNotNull($account1125);

        $debit1125 = DB::table('journal_lines')
            ->where('journal_entry_id', $journal->id)
            ->where('account_id', $account1125->id)
            ->sum('debit_amount');
        $credit1125 = DB::table('journal_lines')
            ->where('journal_entry_id', $journal->id)
            ->where('account_id', $account1125->id)
            ->sum('credit_amount');

        $net1125Balance = (float) $debit1125 - (float) $credit1125;
        $this->assertSame(48000.00, $net1125Balance, 'Account 1125 balance must exactly equal net_payout_amount (48,000.00)');

        // Step 3: Dispatch ofd.payout.settled event (Platform transfers net_payout_amount to Bank)
        $outboxPayout = $dispatcher->dispatchOfdPayoutSettled(
            tenantId: (int) $this->tenant->id,
            storeId: (int) $this->store->id,
            payoutReference: 'PAYOUT-GF-'.rand(1000, 9999),
            settledAmount: 48000.00
        );

        $payoutJournal = $postingEngine->processOutboxEvent($outboxPayout);
        $this->assertNotNull($payoutJournal);

        // Check cumulative balance of Account 1125 across both journals:
        // Debits: 60,000. Credits: 12,000 (commission) + 48,000 (payout settled) = 60,000
        // Net balance: 60,000 - 60,000 = 0.00!
        $allDebit1125 = DB::table('journal_lines')
            ->whereIn('journal_entry_id', [$journal->id, $payoutJournal->id])
            ->where('account_id', $account1125->id)
            ->sum('debit_amount');
        $allCredit1125 = DB::table('journal_lines')
            ->whereIn('journal_entry_id', [$journal->id, $payoutJournal->id])
            ->where('account_id', $account1125->id)
            ->sum('credit_amount');

        $final1125Balance = (float) $allDebit1125 - (float) $allCredit1125;
        $this->assertSame(0.00, $final1125Balance, 'Account 1125 must be cleared to exactly Rp 0,00 after payout settlement!');
    }
}
