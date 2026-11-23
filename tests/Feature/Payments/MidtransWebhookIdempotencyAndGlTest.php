<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Core\Context\TenantContext;
use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentMethod;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Finance\Enums\AccountingPeriodStatusEnum;
use App\Modules\Finance\Enums\JournalStatusEnum;
use App\Modules\Finance\Models\AccountingPeriod;
use App\Modules\Finance\Models\FinancialEventOutbox;
use App\Modules\Finance\Services\PostingEngineService;
use App\Modules\Organization\Models\Role;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Models\Tenant;
use App\Modules\Organization\Models\User;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderItem;
use App\Modules\Sales\Models\Payment;
use App\Modules\Sales\Models\PaymentAttempt;
use App\Modules\Sales\Models\Shift;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DefaultPostingRulesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class MidtransWebhookIdempotencyAndGlTest extends TestCase
{
    use RefreshDatabase;

    private const MIDTRANS_TEST_SERVER_KEY = 'midtrans_secret_key_audit_sha512_test';

    protected Tenant $tenantA;

    protected Store $storeA;

    protected User $cashierA;

    protected Shift $shiftA;

    protected AccountingPeriod $periodA;

    protected Product $productA;

    protected PostingEngineService $postingEngine;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        Config::set('services.midtrans.server_key', self::MIDTRANS_TEST_SERVER_KEY);

        $this->tenantA = $this->defaultTenant;
        $this->storeA = $this->defaultStore;

        app(ChartOfAccountsSeeder::class)->seedForTenant($this->tenantA->id);
        app(DefaultPostingRulesSeeder::class)->seedForTenant($this->tenantA->id);

        $this->postingEngine = app(PostingEngineService::class);

        $this->periodA = AccountingPeriod::create([
            'tenant_id' => $this->tenantA->id,
            'period_code' => now()->format('Y-m'),
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
            'fiscal_year' => (int) now()->format('Y'),
            'period_number' => (int) now()->format('n'),
            'status' => AccountingPeriodStatusEnum::OPEN->value,
            'is_adjustment_period' => false,
        ]);

        $roleOwner = Role::withoutTenantScope()->firstOrCreate(
            ['name' => 'Owner', 'tenant_id' => $this->tenantA->id],
            ['role_code' => 'ROL-OWN', 'is_active' => true, 'permissions' => []]
        );

        $this->cashierA = User::factory()->create([
            'name' => 'Kasir A Utama',
            'email' => 'kasirA@apeirontest.com',
            'tenant_id' => $this->tenantA->id,
            'role_id' => $roleOwner->id,
            'email_verified_at' => now(),
        ]);
        $this->cashierA->stores()->syncWithoutDetaching([$this->storeA->id => ['tenant_id' => $this->tenantA->id]]);

        $this->shiftA = Shift::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->cashierA->id,
            'active_cashier_id' => $this->cashierA->id,
            'starting_balance' => 100000,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $category = Category::withoutTenantScope()->create([
            'category_name' => 'Kategori Retail Utama',
            'tenant_id' => $this->tenantA->id,
        ]);

        $this->productA = Product::withoutTenantScope()->create([
            'product_name' => 'Produk Audit Midtrans',
            'tenant_id' => $this->tenantA->id,
            'category_id' => $category->id,
            'product_price' => 100000,
            'standard_cost' => 60000,
            'stock' => 50,
            'is_active' => true,
        ]);
    }

    private function computeSignature(string $orderId, string $statusCode, string $grossAmount, ?string $serverKey = null): string
    {
        $key = $serverKey ?? self::MIDTRANS_TEST_SERVER_KEY;

        return hash('sha512', $orderId.$statusCode.$grossAmount.$key);
    }

    /**
     * Skenario 1A: Midtrans webhook ditolak dengan fail-closed 401 jika server_key kosong.
     */
    public function test_webhook_rejects_missing_server_key_with_401(): void
    {
        Config::set('services.midtrans.server_key', '');

        $response = $this->postJson('/midtrans/notification', [
            'order_id' => 'ORD-MIDTRANS-TEST-001',
            'status_code' => '200',
            'gross_amount' => '111000.00',
            'signature_key' => 'any_signature_value',
            'transaction_status' => 'settlement',
        ]);

        $response->assertStatus(401)
            ->assertJson(['message' => 'server misconfigured']);
    }

    /**
     * Skenario 1B: Midtrans webhook ditolak dengan 403 jika signature SHA-512 tidak valid.
     */
    public function test_webhook_rejects_invalid_sha512_signature_with_403(): void
    {
        $response = $this->postJson('/midtrans/notification', [
            'order_id' => 'ORD-MIDTRANS-TEST-002',
            'status_code' => '200',
            'gross_amount' => '111000.00',
            'signature_key' => 'tampered_sha512_hash_value_that_fails_validation',
            'transaction_status' => 'settlement',
        ]);

        $response->assertStatus(403)
            ->assertJson(['message' => 'invalid signature']);
    }

    /**
     * Skenario 1C: Midtrans webhook menolak spoofing lintas-tenant jika PaymentAttempt berbeda tenant dari Order.
     */
    public function test_webhook_rejects_cross_tenant_attempt_spoofing_with_403(): void
    {
        // Buat Tenant B yang terpisah
        $tenantB = app(TenantContext::class)->runWithoutTenant(function () {
            return Tenant::create([
                'name' => 'Tenant B Attacker',
                'slug' => 'tenant-b-'.uniqid(),
                'status' => 'active',
                'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
                'access_status' => Tenant::ACCESS_ACTIVE,
            ]);
        });

        $storeB = app(TenantContext::class)->runWithoutTenant(function () use ($tenantB) {
            return Store::withoutTenantScope()->create([
                'tenant_id' => $tenantB->id,
                'name' => 'Store B Attacker',
                'is_active' => true,
            ]);
        });

        $orderA = Order::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->cashierA->id,
            'order_code' => 'ORD-A-SPOOF-01',
            'idempotency_key' => 'idemp-spoof-a1',
            'order_date' => now()->toDateString(),
            'order_status' => OrderStatus::Pending,
            'order_amount' => 111000,
            'subtotal_amount' => 100000,
            'tax_amount' => 11000,
            'discount_amount' => 0,
            'payment_method' => PaymentMethod::Qris,
        ]);

        // Attempt didaftarkan di bawah Tenant B tapi mereferensikan order_id Tenant A
        // Bypass foreign key sementara khusus untuk menyimulasikan anomali/spoofing data lintas-tenant
        $attemptCrossTenant = app(TenantContext::class)->runAs($tenantB->id, $storeB->id, function () use ($tenantB, $storeB, $orderA) {
            \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            try {
                return PaymentAttempt::forceCreate([
                    'tenant_id' => $tenantB->id,
                    'store_id' => $storeB->id,
                    'order_id' => $orderA->id,
                    'provider' => 'midtrans',
                    'provider_order_id' => 'MIDTRANS-CROSS-SPOOF-99',
                    'amount' => 111000,
                    'status' => PaymentAttemptStatus::Initiated,
                    'idempotency_key' => 'attempt-cross-99',
                    'payment_method' => PaymentMethod::Qris->value,
                ]);
            } finally {
                \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            }
        });

        app(TenantContext::class)->setTenantId($this->tenantA->id);

        $signature = $this->computeSignature($attemptCrossTenant->provider_order_id, '200', '111000.00');

        $response = $this->postJson('/midtrans/notification', [
            'order_id' => $attemptCrossTenant->provider_order_id,
            'status_code' => '200',
            'gross_amount' => '111000.00',
            'signature_key' => $signature,
            'transaction_status' => 'settlement',
            'payment_type' => 'qris',
            'transaction_id' => 'TX-SPOOF-001',
        ]);

        $response->assertStatus(403)
            ->assertJson(['message' => 'cross-tenant spoofing detected']);

        // Pastikan order Tenant A tidak terpengaruh / tetap Pending
        $orderA->refresh();
        $this->assertEquals(OrderStatus::Pending->value, $orderA->order_status->value ?? $orderA->order_status);
    }

    /**
     * Skenario 2: Webhook valid pertama mengubah status Order jadi Paid, membuat tepat 1 Payment,
     * dan menghasilkan outbox GL pos.order.paid dengan jurnal berimbang Debit == Credit (BCMath).
     */
    public function test_valid_settlement_webhook_marks_order_paid_creates_single_payment_and_dispatches_gl_outbox(): void
    {
        $order = Order::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->cashierA->id,
            'order_code' => 'ORD-VALID-SETTLE-01',
            'idempotency_key' => 'idemp-valid-01',
            'order_date' => now()->toDateString(),
            'order_status' => OrderStatus::Pending,
            'order_amount' => 111000,
            'subtotal_amount' => 100000,
            'tax_amount' => 11000,
            'discount_amount' => 0,
            'payment_method' => PaymentMethod::Qris,
        ]);

        OrderItem::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'order_id' => $order->id,
            'product_id' => $this->productA->id,
            'quantity' => 1,
            'unit_price' => 100000,
            'preparation_status' => 'pending',
        ]);

        $attempt = PaymentAttempt::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'order_id' => $order->id,
            'provider' => 'midtrans',
            'provider_order_id' => 'MIDTRANS-VALID-ORD-01',
            'amount' => 111000,
            'status' => PaymentAttemptStatus::Initiated,
            'idempotency_key' => 'attempt-valid-01',
            'payment_method' => PaymentMethod::Qris->value,
        ]);

        $signature = $this->computeSignature('MIDTRANS-VALID-ORD-01', '200', '111000.00');

        $response = $this->postJson('/midtrans/notification', [
            'order_id' => 'MIDTRANS-VALID-ORD-01',
            'status_code' => '200',
            'gross_amount' => '111000.00',
            'signature_key' => $signature,
            'transaction_status' => 'settlement',
            'payment_type' => 'qris',
            'transaction_id' => 'TX-VALID-MIDTRANS-999',
        ]);

        $response->assertStatus(200)
            ->assertJson(['message' => 'ok']);

        // 1. Verifikasi Order berstatus Paid
        $order->refresh();
        $this->assertEquals(OrderStatus::Paid->value, $order->order_status->value ?? $order->order_status);

        // 2. Verifikasi PaymentAttempt berstatus Succeeded
        $attempt->refresh();
        $this->assertEquals(PaymentAttemptStatus::Succeeded->value, $attempt->status->value ?? $attempt->status);

        // 3. Verifikasi tepat 1 Payment record tercipta
        $payments = Payment::withoutGlobalScopes()->where('order_id', $order->id)->get();
        $this->assertCount(1, $payments);
        $this->assertEquals(111000, (int) $payments->first()->amount);

        // 4. Verifikasi FinancialEventOutbox terbentuk untuk pos.order.paid
        $outbox = FinancialEventOutbox::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantA->id)
            ->where('event_type', 'pos.order.paid')
            ->where('source_id', $order->id)
            ->first();

        $this->assertNotNull($outbox, 'Outbox event pos.order.paid wajib tercipta saat webhook settlement.');
        $this->assertEquals('111000.00', $outbox->payload['total_amount']);
        $this->assertEquals('100000.00', $outbox->payload['subtotal']);
        $this->assertEquals('11000.00', $outbox->payload['tax_amount']);
        $this->assertEquals('111000.00', $outbox->payload['pg_clearing_amount']);

        // 5. Verifikasi Jurnal GL Double-Entry seimbang: SUM(debit) == SUM(credit)
        $journal = $this->postingEngine->processOutboxEvent($outbox);
        $this->assertEquals(JournalStatusEnum::POSTED->value, $journal->status->value);

        // Invarian FIN-M7-01: Total Debit == Total Credit
        $this->assertEquals($journal->total_debit, $journal->total_credit);
        $this->assertTrue(bccomp($journal->total_debit, '0.00', 2) > 0);

        // Verifikasi akun Kliring Pembayaran Non-Tunai (Debit) dan Penjualan + PPN (Credit)
        $lines = $journal->lines;
        $clearingLine = $lines->first(fn ($l) => $l->account->system_account_key === 'PG_CLEARING');
        $this->assertNotNull($clearingLine, 'Wajib ada baris debit PG_CLEARING');
        $this->assertEquals('111000.00', $clearingLine->debit_amount);

        $salesLine = $lines->first(fn ($l) => $l->account->system_account_key === 'SALES_RETAIL');
        $this->assertNotNull($salesLine, 'Wajib ada baris credit SALES_RETAIL');
        $this->assertEquals('100000.00', $salesLine->credit_amount);

        $taxLine = $lines->first(fn ($l) => $l->account->system_account_key === 'TAX_OUTPUT_PPN');
        $this->assertNotNull($taxLine, 'Wajib ada baris credit TAX_OUTPUT_PPN');
        $this->assertEquals('11000.00', $taxLine->credit_amount);
    }

    /**
     * Skenario 3: Webhook duplikat (dikirim 3x berturut-turut) diakui 200 OK secara idempoten
     * TANPA duplikasi pembayaran, TANPA duplikasi stock movement, dan TANPA duplikasi outbox GL.
     */
    public function test_duplicate_webhook_deliveries_are_acknowledged_idempotently_without_duplicate_payments_or_outbox(): void
    {
        $order = Order::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->cashierA->id,
            'order_code' => 'ORD-IDEMP-RETRY-01',
            'idempotency_key' => 'idemp-retry-01',
            'order_date' => now()->toDateString(),
            'order_status' => OrderStatus::Pending,
            'order_amount' => 111000,
            'subtotal_amount' => 100000,
            'tax_amount' => 11000,
            'discount_amount' => 0,
            'payment_method' => PaymentMethod::Qris,
        ]);

        $attempt = PaymentAttempt::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'order_id' => $order->id,
            'provider' => 'midtrans',
            'provider_order_id' => 'MIDTRANS-IDEMP-ORD-01',
            'amount' => 111000,
            'status' => PaymentAttemptStatus::Initiated,
            'idempotency_key' => 'attempt-retry-01',
            'payment_method' => PaymentMethod::Qris->value,
        ]);

        $signature = $this->computeSignature('MIDTRANS-IDEMP-ORD-01', '200', '111000.00');

        $webhookPayload = [
            'order_id' => 'MIDTRANS-IDEMP-ORD-01',
            'status_code' => '200',
            'gross_amount' => '111000.00',
            'signature_key' => $signature,
            'transaction_status' => 'settlement',
            'payment_type' => 'qris',
            'transaction_id' => 'TX-RETRY-CONCURRENCY-100',
        ];

        // Pengiriman #1: Webhook awal diproses sukses
        $res1 = $this->postJson('/midtrans/notification', $webhookPayload);
        $res1->assertStatus(200);

        // Pengiriman #2: Duplikat webhook pertama (retry Midtrans)
        $res2 = $this->postJson('/midtrans/notification', $webhookPayload);
        $res2->assertStatus(200);

        // Pengiriman #3: Duplikat webhook kedua (retry Midtrans kedua)
        $res3 = $this->postJson('/midtrans/notification', $webhookPayload);
        $res3->assertStatus(200);

        // Verifikasi ketat: Tabel payments HANYA berisi tepat 1 record untuk order ini
        $paymentCount = Payment::withoutGlobalScopes()->where('order_id', $order->id)->count();
        $this->assertEquals(1, $paymentCount, 'Duplikat webhook tidak boleh membuat duplikasi record di tabel payments.');

        // Verifikasi ketat: Tabel financial_event_outboxes HANYA berisi tepat 1 record untuk pos.order.paid order ini
        $outboxCount = FinancialEventOutbox::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantA->id)
            ->where('event_type', 'pos.order.paid')
            ->where('source_id', $order->id)
            ->count();
        $this->assertEquals(1, $outboxCount, 'Duplikat webhook tidak boleh membuat duplikasi event outbox GL.');

        // Verifikasi idempotensi PostingEngineService
        $outbox = FinancialEventOutbox::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantA->id)
            ->where('event_type', 'pos.order.paid')
            ->where('source_id', $order->id)
            ->firstOrFail();

        $journal1 = $this->postingEngine->processOutboxEvent($outbox);
        $journal2 = $this->postingEngine->processOutboxEvent($outbox);
        $this->assertEquals($journal1->id, $journal2->id, 'Posting engine wajib mengembalikan jurnal yang sama tanpa duplikasi.');
    }

    /**
     * Skenario 4: Webhook expire atau cancel TIDAK menandai order sebagai Paid dan tidak membuat Payment/Outbox.
     */
    public function test_webhook_with_expire_or_cancel_does_not_mark_order_as_paid(): void
    {
        $order = Order::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->cashierA->id,
            'order_code' => 'ORD-EXPIRE-TEST-01',
            'idempotency_key' => 'idemp-exp-01',
            'order_date' => now()->toDateString(),
            'order_status' => OrderStatus::Pending,
            'order_amount' => 50000,
            'subtotal_amount' => 50000,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'payment_method' => PaymentMethod::Qris,
        ]);

        $attempt = PaymentAttempt::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'order_id' => $order->id,
            'provider' => 'midtrans',
            'provider_order_id' => 'MIDTRANS-EXP-ORD-01',
            'amount' => 50000,
            'status' => PaymentAttemptStatus::Initiated,
            'idempotency_key' => 'attempt-exp-01',
            'payment_method' => PaymentMethod::Qris->value,
        ]);

        $signature = $this->computeSignature('MIDTRANS-EXP-ORD-01', '200', '50000.00');

        $response = $this->postJson('/midtrans/notification', [
            'order_id' => 'MIDTRANS-EXP-ORD-01',
            'status_code' => '200',
            'gross_amount' => '50000.00',
            'signature_key' => $signature,
            'transaction_status' => 'expire',
            'payment_type' => 'qris',
            'transaction_id' => 'TX-EXPIRE-999',
        ]);

        $response->assertStatus(200);

        $order->refresh();
        $this->assertNotEquals(OrderStatus::Paid->value, $order->order_status->value ?? $order->order_status);

        // Nol payment dan nol outbox GL
        $this->assertEquals(0, Payment::withoutGlobalScopes()->where('order_id', $order->id)->count());
        $this->assertEquals(0, FinancialEventOutbox::withoutGlobalScopes()->where('source_id', $order->id)->count());
    }

    /**
     * Skenario 5: Endpoint print payload ESC/POS thermal memiliki tenant-scoping ketat (403/404)
     * dan menghasilkan payload cetak lengkap jika diakses oleh kasir tenant yang berwenang.
     */
    public function test_thermal_print_payload_endpoint_returns_receipt_structure_and_enforces_tenant_isolation(): void
    {
        // 1. Order lunas di Tenant A
        $orderA = Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->cashierA->id,
            'order_code' => 'ORD-PRINT-AUTH-A1',
            'idempotency_key' => 'idemp-print-a1',
            'order_date' => now()->toDateString(),
            'order_status' => OrderStatus::Paid,
            'order_amount' => 100000,
            'subtotal_amount' => 100000,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'payment_method' => PaymentMethod::Cash,
        ]);

        OrderItem::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'order_id' => $orderA->id,
            'product_id' => $this->productA->id,
            'quantity' => 1,
            'unit_price' => 100000,
            'preparation_status' => 'ready',
        ]);

        // 2. Order di Tenant B
        $tenantB = app(TenantContext::class)->runWithoutTenant(function () {
            return Tenant::create([
                'name' => 'Tenant B Rival',
                'slug' => 'tenant-b-rival-'.uniqid(),
                'status' => 'active',
                'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
                'access_status' => Tenant::ACCESS_ACTIVE,
            ]);
        });
        $storeB = app(TenantContext::class)->runWithoutTenant(function () use ($tenantB) {
            return Store::withoutTenantScope()->create([
                'tenant_id' => $tenantB->id,
                'name' => 'Store B',
                'is_active' => true,
            ]);
        });
        $orderB = app(TenantContext::class)->runAs($tenantB->id, $storeB->id, function () use ($tenantB, $storeB) {
            $roleOwnerB = Role::withoutTenantScope()->firstOrCreate(
                ['name' => 'Owner', 'tenant_id' => $tenantB->id],
                ['role_code' => 'ROL-OWN-B', 'is_active' => true, 'permissions' => []]
            );
            $userB = User::factory()->create([
                'tenant_id' => $tenantB->id,
                'role_id' => $roleOwnerB->id,
                'email_verified_at' => now(),
            ]);
            $userB->stores()->syncWithoutDetaching([$storeB->id => ['tenant_id' => $tenantB->id]]);

            return Order::forceCreate([
                'tenant_id' => $tenantB->id,
                'store_id' => $storeB->id,
                'user_id' => $userB->id,
                'order_code' => 'ORD-PRINT-SECRET-B1',
                'idempotency_key' => 'idemp-print-b1',
                'order_date' => now()->toDateString(),
                'order_status' => OrderStatus::Paid,
                'order_amount' => 75000,
                'subtotal_amount' => 75000,
                'tax_amount' => 0,
                'discount_amount' => 0,
                'payment_method' => PaymentMethod::Cash,
            ]);
        });

        app(TenantContext::class)->setTenantId($this->tenantA->id);

        // Akses oleh Kasir A terhadap Order Tenant A -> 200 OK dengan payload cetak ESC/POS
        $responseA = $this->actingAs($this->cashierA)
            ->getJson("/api/orders/{$orderA->order_code}/print-payload");

        $responseA->assertStatus(200)
            ->assertJsonStructure([
                'receipt_base64',
                'drawer_hex',
                'print_sequence',
                'is_reprint',
                'receipt_text',
            ]);

        // Akses oleh Kasir A terhadap Order Tenant B -> WAJIB ditolak (404 Not Found / 403 Forbidden fail-closed)
        $responseCrossTenant = $this->actingAs($this->cashierA)
            ->getJson("/api/orders/{$orderB->order_code}/print-payload");

        $this->assertContains($responseCrossTenant->status(), [403, 404], 'Kasir Tenant A dilarang keras mengakses print payload Tenant B.');
    }
}
