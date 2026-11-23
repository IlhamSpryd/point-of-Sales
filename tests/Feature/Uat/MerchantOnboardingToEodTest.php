<?php

declare(strict_types=1);

namespace Tests\Feature\Uat;

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
use App\Modules\Inventory\Models\ProductStockBalance;
use App\Modules\Organization\Models\Company;
use App\Modules\Organization\Models\Role;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Models\Tenant;
use App\Modules\Organization\Models\User;
use App\Modules\Sales\Models\CashDrawerMovement;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderItem;
use App\Modules\Sales\Models\Payment;
use App\Modules\Sales\Models\PaymentAttempt;
use App\Modules\Sales\Models\Shift;
use App\Modules\Sales\Services\TransactionService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DefaultPostingRulesSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Merchant Onboarding to End-of-Day (EOD) Lifecycle UAT Suite (Wave 3C).
 *
 * Validates the complete 8-step production merchant operational cycle:
 * Stage A: Pendaftaran & Onboarding Tenant Baru
 * Stage B: Pembuatan Cabang/Store Pertama & Kasir Baru (RBAC + PIN)
 * Stage C: Pembukaan Shift Kasir (`shifts/open` dengan opening_cash)
 * Stage D: Transaksi POS Kasir Tunai (potong stok, outbox GL, cash drawer)
 * Stage E: Transaksi POS QRIS Midtrans (settlement idempoten & multi-tenancy)
 * Stage F: Thermal Print Payload ESC/POS untuk QZ Tray
 * Stage G: Penutupan Shift Kasir EOD (`shifts/{shift}/close` rekonsiliasi kas)
 * Stage H: Rekonsiliasi GL Double-Entry seimbang & Laporan Finansial tanpa Division by Zero
 */
class MerchantOnboardingToEodTest extends TestCase
{
    use DatabaseTransactions;

    protected bool $optOutFromDefaultTenant = true;

    private const MIDTRANS_SERVER_KEY = 'uat_midtrans_server_key_eod_lifecycle_2026';

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        Config::set('services.midtrans.server_key', self::MIDTRANS_SERVER_KEY);
    }

    private function computeMidtransSignature(string $orderId, string $statusCode, string $grossAmount): string
    {
        return hash('sha512', $orderId.$statusCode.$grossAmount.self::MIDTRANS_SERVER_KEY);
    }

    public function test_complete_merchant_onboarding_to_eod_lifecycle(): void
    {
        $tenantContext = app(TenantContext::class);
        $tenantContext->clear();

        // =========================================================================
        // STAGE A: Pendaftaran & Onboarding Tenant Baru
        // =========================================================================
        $tenant = $tenantContext->runWithoutTenant(function () {
            return Tenant::create([
                'name' => 'Kopi Nusantara Group',
                'slug' => 'kopi-nusantara-'.uniqid(),
                'status' => 'active',
                'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
                'access_status' => Tenant::ACCESS_ACTIVE,
            ]);
        });

        $company = $tenantContext->runWithoutTenant(function () use ($tenant) {
            return Company::create([
                'tenant_id' => $tenant->id,
                'company_name' => 'PT Kopi Nusantara Abadi',
                'npwp' => '01.234.567.8-901.000',
                'tax_type' => 'pkp',
                'is_active' => true,
            ]);
        });

        $roleOwner = $tenantContext->runWithoutTenant(function () use ($tenant) {
            return Role::withoutTenantScope()->firstOrCreate(
                ['name' => 'Owner', 'tenant_id' => $tenant->id],
                ['role_code' => 'ROL-OWN-'.uniqid(), 'is_active' => true, 'permissions' => []]
            );
        });

        $owner = $tenantContext->runWithoutTenant(function () use ($tenant, $roleOwner) {
            return User::factory()->create([
                'name' => 'Bambang Sutrisno',
                'email' => 'owner.nusantara.'.uniqid().'@kopi.test',
                'tenant_id' => $tenant->id,
                'role_id' => $roleOwner->id,
                'email_verified_at' => now(),
            ]);
        });

        // Seed Chart of Accounts dan Default Posting Rules untuk tenant baru
        app(ChartOfAccountsSeeder::class)->seedForTenant($tenant->id);
        app(DefaultPostingRulesSeeder::class)->seedForTenant($tenant->id);

        $period = $tenantContext->runWithoutTenant(function () use ($tenant) {
            return AccountingPeriod::create([
                'tenant_id' => $tenant->id,
                'period_code' => now()->format('Y-m'),
                'start_date' => now()->startOfMonth()->toDateString(),
                'end_date' => now()->endOfMonth()->toDateString(),
                'fiscal_year' => (int) now()->format('Y'),
                'period_number' => (int) now()->format('n'),
                'status' => AccountingPeriodStatusEnum::OPEN->value,
                'is_adjustment_period' => false,
            ]);
        });

        $this->assertNotNull($tenant);
        $this->assertNotNull($company);
        $this->assertNotNull($period);

        // =========================================================================
        // STAGE B: Pembuatan Cabang/Store Pertama & Kasir Baru
        // =========================================================================
        $store = $tenantContext->runWithoutTenant(function () use ($tenant) {
            return Store::withoutTenantScope()->create([
                'tenant_id' => $tenant->id,
                'name' => 'Cabang Sudirman Flagship',
                'code' => 'STR-SDR-01',
                'is_active' => true,
            ]);
        });

        $roleKasir = $tenantContext->runWithoutTenant(function () use ($tenant) {
            return Role::withoutTenantScope()->firstOrCreate(
                ['name' => 'Kasir', 'tenant_id' => $tenant->id],
                ['role_code' => 'ROL-KAS-'.uniqid(), 'is_active' => true, 'permissions' => []]
            );
        });

        $cashier = $tenantContext->runWithoutTenant(function () use ($tenant, $store, $roleKasir) {
            $u = User::factory()->create([
                'name' => 'Siti Kasir Pagi',
                'email' => 'siti.'.uniqid().'@kopi.test',
                'password' => Hash::make('secret123'),
                'pin_hash' => Hash::make('123456'), // PIN 6 digit kasir
                'tenant_id' => $tenant->id,
                'role_id' => $roleKasir->id,
                'email_verified_at' => now(),
            ]);
            $u->stores()->syncWithoutDetaching([$store->id => ['tenant_id' => $tenant->id]]);

            return $u;
        });

        $owner->stores()->syncWithoutDetaching([$store->id => ['tenant_id' => $tenant->id]]);

        $category = $tenantContext->runWithoutTenant(function () use ($tenant) {
            return Category::withoutTenantScope()->create([
                'category_name' => 'Signature Coffee Beans',
                'tenant_id' => $tenant->id,
            ]);
        });

        $product = $tenantContext->runWithoutTenant(function () use ($tenant, $category) {
            return Product::withoutTenantScope()->create([
                'product_name' => 'Kopi Arabika Signature 250g',
                'tenant_id' => $tenant->id,
                'category_id' => $category->id,
                'product_price' => 50000.00,
                'standard_cost' => 25000.00,
                'stock' => 100,
                'track_inventory' => true,
                'is_active' => true,
            ]);
        });

        $tenantContext->runWithoutTenant(function () use ($tenant, $store, $product) {
            ProductStockBalance::withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'store_id' => $store->id, 'product_id' => $product->id],
                ['quantity' => 100, 'reserved_quantity' => 0]
            );
        });

        // Set konteks tenant & store aktif untuk sesi kasir
        $tenantContext->setTenantId($tenant->id);
        $tenantContext->setStoreId($store->id);

        // =========================================================================
        // STAGE C: Pembukaan Shift Kasir (`shifts/open`)
        // =========================================================================
        $openShiftResponse = $this->actingAs($cashier)
            ->postJson('/shifts/open', [
                'opening_balance' => 100000, // Kas awal Rp 100.000
            ]);

        $openShiftResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Register shift opened successfully.',
            ]);

        $shift = Shift::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('store_id', $store->id)
            ->where('status', 'open')
            ->first();

        $this->assertNotNull($shift, 'Shift kasir wajib aktif berstatus open.');
        $this->assertEquals(100000, (int) $shift->opening_balance);

        // Pulihkan konteks tenant & store aktif pada test runner setelah request HTTP selesai
        $tenantContext->setTenantId($tenant->id);
        $tenantContext->setStoreId($store->id);

        // =========================================================================
        // STAGE D: Transaksi POS Kasir Pembayaran Tunai
        // =========================================================================
        $transactionService = app(TransactionService::class);

        // Kasir melayani pembelian tunai: 1 bungkus kopi (Rp 50.000 + PPN 11% Rp 5.500 = Rp 55.500)
        // Dibayar uang Rp 100.000, kembalian Rp 44.500
        $cashOrder = $transactionService->createTransaction([
            'store_id' => $store->id,
            'shift_id' => $shift->id,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 50000,
                ],
            ],
            'payments' => [
                [
                    'method' => 'cash',
                    'amount' => 55500,
                    'amount_tendered' => 100000,
                    'change_amount' => 44500,
                ],
            ],
            'idempotency_key' => 'POS-CASH-UAT-'.uniqid(),
        ], $cashier->id);

        $this->assertEquals(OrderStatus::Paid->value, $cashOrder->order_status->value ?? $cashOrder->order_status);

        // Verifikasi stok berkurang dari 100 menjadi 99
        $stockAfterCash = (int) ProductStockBalance::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('store_id', $store->id)
            ->where('product_id', $product->id)
            ->value('quantity');
        $this->assertEquals(99, $stockAfterCash, 'Stok produk wajib terpotong tepat 1 unit.');

        // Verifikasi pembayaran tunai tercatat pada tabel payments
        $cashPayment = Payment::withoutGlobalScopes()
            ->where('order_id', $cashOrder->id)
            ->where('payment_method', 'cash')
            ->first();
        $this->assertNotNull($cashPayment, 'Payment cash wajib tercatat.');
        $this->assertEquals(55500, (int) $cashPayment->amount);

        // Kasir melakukan pencatatan kas laci (cash_in modal kembalian tambahan Rp 10.000)
        $cashMovementResponse = $this->actingAs($cashier)
            ->postJson("/shifts/{$shift->id}/movement", [
                'type' => 'cash_in',
                'amount' => 10000,
                'category' => 'restock_change',
                'reason' => 'Uang modal kembalian tambahan dari brankas',
            ]);
        $cashMovementResponse->assertStatus(200);

        // Pulihkan konteks setelah request HTTP
        $tenantContext->setTenantId($tenant->id);
        $tenantContext->setStoreId($store->id);

        $cashMovement = CashDrawerMovement::withoutGlobalScopes()
            ->where('shift_id', $shift->id)
            ->where('type', 'cash_in')
            ->first();
        $this->assertNotNull($cashMovement, 'Cash in wajib dicatat di cash drawer movement.');
        $this->assertEquals(10000, (int) $cashMovement->amount);

        // Verifikasi outbox pos.order.paid terbentuk
        $cashOutbox = FinancialEventOutbox::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('event_type', 'pos.order.paid')
            ->where('source_id', $cashOrder->id)
            ->first();
        $this->assertNotNull($cashOutbox);

        // =========================================================================
        // STAGE E: Transaksi POS Pembayaran Non-Tunai / QRIS Midtrans
        // =========================================================================
        // Pembelian 2 bungkus kopi (subtotal Rp 100.000 + PPN Rp 11.000 = Rp 111.000)
        $qrisOrder = Order::forceCreate([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'user_id' => $cashier->id,
            'shift_id' => $shift->id,
            'order_code' => 'ORD-QRIS-EOD-'.uniqid(),
            'idempotency_key' => 'idemp-qris-eod-'.uniqid(),
            'order_date' => now()->toDateString(),
            'order_status' => OrderStatus::Pending,
            'order_amount' => 111000,
            'subtotal_amount' => 100000,
            'tax_amount' => 11000,
            'discount_amount' => 0,
            'payment_method' => PaymentMethod::Qris,
        ]);

        OrderItem::forceCreate([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'order_id' => $qrisOrder->id,
            'product_id' => $product->id,
            'qty' => 2,
            'order_price' => 50000,
            'order_subtotal' => 100000,
            'preparation_status' => 'pending',
        ]);

        $attempt = PaymentAttempt::forceCreate([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'order_id' => $qrisOrder->id,
            'provider' => 'midtrans',
            'provider_order_id' => 'MIDTRANS-EOD-ATTEMPT-01',
            'amount' => 111000,
            'status' => PaymentAttemptStatus::Initiated,
            'idempotency_key' => 'attempt-eod-01',
            'payment_method' => PaymentMethod::Qris->value,
        ]);

        $qrisSignature = $this->computeMidtransSignature('MIDTRANS-EOD-ATTEMPT-01', '200', '111000.00');

        $webhookPayload = [
            'order_id' => 'MIDTRANS-EOD-ATTEMPT-01',
            'status_code' => '200',
            'gross_amount' => '111000.00',
            'signature_key' => $qrisSignature,
            'transaction_status' => 'settlement',
            'payment_type' => 'qris',
            'transaction_id' => 'TRX-MIDTRANS-EOD-888',
        ];

        // Simulasi webhook pertama
        $webhookRes1 = $this->postJson('/midtrans/notification', $webhookPayload);
        $webhookRes1->assertStatus(200);

        // Simulasi retry webhook kedua (idempotency verification)
        $webhookRes2 = $this->postJson('/midtrans/notification', $webhookPayload);
        $webhookRes2->assertStatus(200);

        $qrisOrder->refresh();
        $this->assertEquals(OrderStatus::Paid->value, $qrisOrder->order_status->value ?? $qrisOrder->order_status);

        // Pastikan hanya ada 1 payment untuk order QRIS ini
        $this->assertEquals(1, Payment::withoutGlobalScopes()->where('order_id', $qrisOrder->id)->count());

        // =========================================================================
        // STAGE F: Thermal Print Payload ESC/POS untuk QZ Tray Kasir
        // =========================================================================
        $printResponse = $this->actingAs($cashier)
            ->getJson("/api/orders/{$cashOrder->order_code}/print-payload");

        $printResponse->assertStatus(200)
            ->assertJsonStructure([
                'receipt_base64',
                'drawer_hex',
                'print_sequence',
                'is_reprint',
                'receipt_text',
            ]);

        $this->assertEquals(1, $printResponse->json('print_sequence'));
        $this->assertFalse($printResponse->json('is_reprint'));

        // =========================================================================
        // STAGE G: Penutupan Shift Kasir EOD (`shifts/{shift}/close`)
        // =========================================================================
        // Kas awal: Rp 100.000 + Penjualan tunai: Rp 55.500 + Cash In: Rp 10.000 = Total kas fisik sistem: Rp 165.500
        $closeShiftResponse = $this->actingAs($cashier)
            ->postJson("/shifts/{$shift->id}/close", [
                'closing_balance' => 165500,
                'notes' => 'EOD shift closing normal tanpa selisih',
            ]);

        $closeShiftResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Register shift closed successfully.',
            ]);

        $shift->refresh();
        $this->assertEquals('closed', $shift->status);
        $this->assertEquals(165500, (int) $shift->closing_balance);
        $this->assertEquals(165500, (int) $shift->expected_cash);
        $this->assertEquals(0, (int) $shift->cash_difference);

        // Pulihkan konteks setelah penutupan shift
        $tenantContext->setTenantId($tenant->id);
        $tenantContext->setStoreId($store->id);

        // =========================================================================
        // STAGE H: Verifikasi General Ledger Seimbang & Uji Laporan Tanpa Division by Zero
        // =========================================================================
        $postingEngine = app(PostingEngineService::class);

        $pendingOutboxes = FinancialEventOutbox::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->get();

        foreach ($pendingOutboxes as $outbox) {
            $journal = $postingEngine->processOutboxEvent($outbox);
            $this->assertEquals(JournalStatusEnum::POSTED->value, $journal->status->value);

            // Invarian FIN-M7-01: SUM(debit) == SUM(credit)
            $this->assertEquals(
                $journal->total_debit,
                $journal->total_credit,
                "Jurnal #{$journal->journal_number} outbox event {$outbox->event_type} wajib berimbang."
            );
            $this->assertTrue(bccomp($journal->total_debit, '0.00', 2) > 0);
        }

        // Uji Dashboard & Endpoints Finansial bebas dari error Division by Zero
        $this->actingAs($owner);

        $dashRes = $this->get('/dashboard');
        $dashRes->assertStatus(200);

        $finRes = $this->get('/finance');
        $finRes->assertStatus(200);

        $revSeriesRes = $this->getJson('/dashboard/api/revenue-series');
        $revSeriesRes->assertStatus(200);

        $payVolRes = $this->getJson('/dashboard/api/payment-volume');
        $payVolRes->assertStatus(200);

        $profComboRes = $this->getJson('/dashboard/api/profitability-combo');
        $profComboRes->assertStatus(200);
    }
}
