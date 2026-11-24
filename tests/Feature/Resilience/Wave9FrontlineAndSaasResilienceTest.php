<?php

declare(strict_types=1);

namespace Tests\Feature\Resilience;

use App\Core\Context\TenantContext;
use App\Enums\ExportTaskStatus;
use App\Enums\KitchenStation;
use App\Enums\OrderStatus;
use App\Livewire\Kasir\CreateOrder;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\UnitOfMeasure;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Models\Tenant;
use App\Modules\Organization\Models\User;
use App\Modules\Reporting\Models\ExportTask;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderItem;
use App\Modules\Sales\Models\Shift;
use App\Modules\Sales\Services\OfflineOrderSyncService;
use App\Modules\Sales\Services\Printing\KitchenPrinterService;
use App\Modules\Subscription\Application\Services\SubscriptionBillingOrchestrator;
use App\Modules\Subscription\Models\Plan;
use App\Modules\Subscription\Models\ProvisioningRequest;
use App\Modules\Subscription\Models\TenantSubscription;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class Wave9FrontlineAndSaasResilienceTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Store $store;

    protected User $user;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        app(TenantContext::class)->clear();

        $this->tenant = Tenant::create([
            'name' => 'Wave9 Resilience Tenant',
            'slug' => 'wave9-resilience-'.uniqid(),
            'status' => 'active',
            'subscription_status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Main Outlet',
            'code' => substr('W9'.uniqid(), 0, 10),
            'is_active' => true,
        ]);

        app(TenantContext::class)->setStoreId($this->store->id);

        $role = \App\Modules\Organization\Models\Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Kasir',
            'permissions' => ['*'],
        ]);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        DB::table('user_stores')->insert([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
        ]);

        Auth::login($this->user);
    }

    public function test_subscription_webhook_rejects_tampered_lower_gross_amount(): void
    {
        $plan = Plan::create([
            'name' => 'Enterprise Plan',
            'code' => 'enterprise-'.Str::random(4),
            'price' => 5000000.00,
            'billing_cycle' => 'monthly',
            'is_active' => true,
        ]);

        $orderCode = 'SUB-'.$this->tenant->id.'-'.$plan->id.'-'.now()->format('YmdHis').'-TAMPER';
        $provisioning = ProvisioningRequest::create([
            'idempotency_key' => 'idemp-'.Str::random(8),
            'request_fingerprint' => 'fp-'.Str::random(8),
            'owner_email' => $this->user->email,
            'status' => ProvisioningRequest::STATUS_PENDING,
            'result_tenant_id' => $this->tenant->id,
            'result_user_id' => $this->user->id,
            'plan_id' => $plan->id,
            'expected_amount' => 5000000.00,
            'correlation_id' => $orderCode,
            'started_at' => now(),
            'attempt_count' => 0,
        ]);

        $orchestrator = app(SubscriptionBillingOrchestrator::class);

        // Attacker memanipulasi gross_amount menjadi 1000.00
        $result = $orchestrator->settleFromWebhook(
            $orderCode,
            'settlement',
            'accept',
            [
                'order_id' => $orderCode,
                'gross_amount' => '1000.00',
                'transaction_status' => 'settlement',
            ]
        );

        $this->assertFalse($result);
        $this->assertEquals(ProvisioningRequest::STATUS_FAILED, $provisioning->fresh()->status);
        $this->assertStringContainsString('Pembayaran tidak mencukupi', (string) $provisioning->fresh()->error_message);
    }

    public function test_subscription_webhook_accepts_and_activates_valid_gross_amount(): void
    {
        $plan = Plan::create([
            'name' => 'Pro Plan',
            'code' => 'pro-'.Str::random(4),
            'price' => 2000000.00,
            'billing_cycle' => 'monthly',
            'is_active' => true,
        ]);

        $orderCode = 'SUB-'.$this->tenant->id.'-'.$plan->id.'-'.now()->format('YmdHis').'-VALID';
        $provisioning = ProvisioningRequest::create([
            'idempotency_key' => 'idemp-'.Str::random(8),
            'request_fingerprint' => 'fp-'.Str::random(8),
            'owner_email' => $this->user->email,
            'status' => ProvisioningRequest::STATUS_PENDING,
            'result_tenant_id' => $this->tenant->id,
            'result_user_id' => $this->user->id,
            'plan_id' => $plan->id,
            'expected_amount' => 2000000.00,
            'correlation_id' => $orderCode,
            'started_at' => now(),
            'attempt_count' => 0,
        ]);

        $orchestrator = app(SubscriptionBillingOrchestrator::class);

        $result = $orchestrator->settleFromWebhook(
            $orderCode,
            'settlement',
            'accept',
            [
                'order_id' => $orderCode,
                'gross_amount' => '2000000.00',
                'transaction_status' => 'settlement',
            ]
        );

        $this->assertTrue($result);
        $this->assertEquals(ProvisioningRequest::STATUS_COMPLETED, $provisioning->fresh()->status);
        $this->assertDatabaseHas('tenant_subscriptions', [
            'tenant_id' => $this->tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
        ]);
    }

    public function test_offline_order_sync_flags_post_close_reconciliation_when_shift_is_closed(): void
    {
        $cashierA = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'is_active' => true,
        ]);

        $cashierB = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'is_active' => true,
        ]);

        // Shift Kasir A telah ditutup
        $shiftA = Shift::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $cashierA->id,
            'active_cashier_id' => $cashierA->id,
            'status' => 'closed',
            'opened_at' => now()->subHours(8),
            'closed_at' => now()->subHours(1),
            'opening_balance' => 100000,
            'closing_balance' => 250000,
        ]);

        $uom = UnitOfMeasure::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'code' => 'PCS'],
            ['name' => 'Pieces', 'is_active' => true]
        );

        $cat = Category::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'category_name' => 'General'],
            ['is_active' => true]
        );

        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'category_id' => $cat->id,
            'product_name' => 'Offline Item',
            'product_code' => 'ITM-OFFLINE-01',
            'product_price' => 50000,
            'uom_id' => $uom->id,
            'track_inventory' => false,
            'stock' => 100,
            'is_active' => true,
        ]);

        $syncService = app(OfflineOrderSyncService::class);
        $clientUuid = (string) Str::uuid();

        $batch = [
            [
                'offline_client_uuid' => $clientUuid,
                'offline_created_by_user_id' => $cashierA->id,
                'offline_shift_id' => $shiftA->id,
                'subtotal_amount' => 50000,
                'order_amount' => 50000,
                'tax_amount' => 0,
                'cash_received' => 50000,
                'order_change' => 0,
                'payment_method' => 'cash',
                'items' => [
                    [
                        'product_id' => $product->id,
                        'quantity' => 1,
                        'unit_price' => 50000,
                        'subtotal' => 50000,
                    ],
                ],
            ],
        ];

        // Kasir B yang sedang login melakukan sinkronisasi
        $result = $syncService->syncBatch(
            $batch,
            userId: $cashierB->id,
            tenantId: $this->tenant->id,
            storeId: $this->store->id
        );

        $this->assertEquals(1, $result['synced_count']);

        $syncedOrder = Order::where('offline_client_uuid', $clientUuid)->first();
        $this->assertNotNull($syncedOrder);
        // User asli tetap diatribusikan ke Kasir A
        $this->assertEquals($cashierA->id, $syncedOrder->user_id);
        // Ditandai sebagai offline_post_close_reconciliation
        $this->assertTrue((bool) $syncedOrder->offline_post_close_reconciliation);
        $this->assertStringContainsString('OFFLINE POST-CLOSE RECONCILIATION', (string) $syncedOrder->source_reference);
    }

    public function test_handle_search_enter_adds_exact_sku_to_cart_and_clears_search(): void
    {
        $uom = UnitOfMeasure::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'code' => 'PCS'],
            ['name' => 'Pieces', 'is_active' => true]
        );

        $cat = Category::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'category_name' => 'General'],
            ['is_active' => true]
        );

        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'category_id' => $cat->id,
            'product_name' => 'Kopi Arabika',
            'product_code' => 'SKU-KOPI-01',
            'barcode' => '8991234567890',
            'product_price' => 25000,
            'uom_id' => $uom->id,
            'is_active' => true,
        ]);

        // Buka shift kasir aktif
        Shift::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'active_cashier_id' => $this->user->id,
            'status' => 'open',
            'opened_at' => now(),
            'opening_balance' => 100000,
        ]);

        Livewire::actingAs($this->user)
            ->test(CreateOrder::class)
            ->call('handleSearchEnter', 'SKU-KOPI-01')
            ->assertSet('search', '')
            ->assertCount('cart', 1);
    }

    public function test_handle_search_enter_keeps_search_query_when_not_exact_barcode(): void
    {
        Shift::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'active_cashier_id' => $this->user->id,
            'status' => 'open',
            'opened_at' => now(),
            'opening_balance' => 100000,
        ]);

        Livewire::actingAs($this->user)
            ->test(CreateOrder::class)
            ->call('handleSearchEnter', 'non_existing_product_keyword')
            ->assertSet('search', 'non_existing_product_keyword')
            ->assertCount('cart', 0);
    }

    public function test_thermal_receipt_includes_rounding_adjustment_when_non_zero(): void
    {
        $order = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'order_code' => 'ORD-ROUND-001',
            'order_date' => now()->toDateString(),
            'order_status' => OrderStatus::Paid,
            'subtotal_amount' => 52340,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'service_charge_amount' => 0,
            'rounding_adjustment' => -40,
            'order_amount' => 52300,
            'idempotency_key' => (string) Str::uuid(),
            'payment_method' => 'cash',
        ]);

        $rendered = view('transaction.receipt', ['order' => $order])->render();

        $this->assertStringContainsString('Pembulatan', $rendered);
        $this->assertStringContainsString('-40', $rendered);
        $this->assertStringContainsString('52.300', $rendered);
    }

    public function test_kitchen_printer_service_splits_by_station_and_includes_payment_status_banner(): void
    {
        $catKitchen = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Makanan Utama',
            'kitchen_station' => KitchenStation::Kitchen,
            'is_active' => true,
        ]);

        $catBar = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Minuman Dingin',
            'kitchen_station' => KitchenStation::Bar,
            'is_active' => true,
        ]);

        $uom = UnitOfMeasure::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'code' => 'PCS'],
            ['name' => 'Pieces', 'is_active' => true]
        );

        $prodKitchen = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $catKitchen->id,
            'product_name' => 'Nasi Goreng Spesial',
            'product_price' => 35000,
            'uom_id' => $uom->id,
            'is_active' => true,
        ]);

        $prodBar = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $catBar->id,
            'product_name' => 'Es Teh Manis',
            'product_price' => 8000,
            'uom_id' => $uom->id,
            'is_active' => true,
        ]);

        $order = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'order_code' => 'ORD-SPLIT-01',
            'order_date' => now()->toDateString(),
            'order_status' => OrderStatus::Paid,
            'subtotal_amount' => 43000,
            'order_amount' => 43000,
            'idempotency_key' => (string) Str::uuid(),
            'payment_method' => 'cash',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $prodKitchen->id,
            'qty' => 2,
            'price' => 35000,
            'subtotal' => 70000,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $prodBar->id,
            'qty' => 1,
            'price' => 8000,
            'subtotal' => 8000,
        ]);

        $service = app(KitchenPrinterService::class);

        // Cetak Stasiun Kitchen
        $payloadKitchen = implode('', $service->generatePayload($order, 'kitchen'));
        $this->assertStringContainsString('TIKET KITCHEN', $payloadKitchen);
        $this->assertStringContainsString('[SUDAH LUNAS]', $payloadKitchen);
        $this->assertStringContainsString('Nasi Goreng Spesial', $payloadKitchen);
        $this->assertStringNotContainsString('Es Teh Manis', $payloadKitchen);

        // Cetak Stasiun Bar
        $payloadBar = implode('', $service->generatePayload($order, 'bar'));
        $this->assertStringContainsString('TIKET BAR', $payloadBar);
        $this->assertStringContainsString('[SUDAH LUNAS]', $payloadBar);
        $this->assertStringContainsString('Es Teh Manis', $payloadBar);
        $this->assertStringNotContainsString('Nasi Goreng Spesial', $payloadBar);
    }

    public function test_prune_exports_command_deletes_stale_files_and_marks_expired(): void
    {
        Storage::fake('local');
        $dummyPath = "exports/tenant_{$this->tenant->id}/stale_report.xlsx";
        Storage::disk('local')->put($dummyPath, 'dummy export content');

        $task = ExportTask::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'requested_by' => $this->user->id,
            'type' => 'sales_report',
            'status' => ExportTaskStatus::Completed,
            'file_path' => $dummyPath,
            'file_size_bytes' => 1024,
        ]);
        $task->created_at = Carbon::now()->subDays(10);
        $task->completed_at = Carbon::now()->subDays(10);
        $task->saveQuietly();

        Artisan::call('exports:prune', ['--days' => 7]);

        Storage::disk('local')->assertMissing($dummyPath);
        $this->assertEquals(ExportTaskStatus::Expired, $task->fresh()->status);
        $this->assertNull($task->fresh()->file_path);
    }

    public function test_evaluate_subscription_status_command_transitions_grace_period_and_suspended(): void
    {
        $plan = Plan::create([
            'name' => 'Starter Plan',
            'code' => 'starter-'.Str::random(4),
            'price' => 100000,
            'is_active' => true,
        ]);

        $savedTenantId = app(TenantContext::class)->getTenantId();
        app(TenantContext::class)->clear();

        // Tenant A: Habis 1 hari yang lalu (Masa Tenggang <= 3 hari) -> past_due
        $tenantA = Tenant::create(['name' => 'Tenant A', 'subscription_status' => 'active', 'status' => 'active']);
        $subA = TenantSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $tenantA->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => Carbon::now()->subDays(31),
            'ends_at' => Carbon::now()->subDay(),
        ]);

        // Tenant B: Habis 5 hari yang lalu (> 3 hari) -> suspended
        $tenantB = Tenant::create(['name' => 'Tenant B', 'subscription_status' => 'active', 'status' => 'active']);
        $subB = TenantSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $tenantB->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => Carbon::now()->subDays(35),
            'ends_at' => Carbon::now()->subDays(5),
        ]);

        Artisan::call('subscription:evaluate-status');

        $this->assertEquals('past_due', $subA->fresh()->status);
        $this->assertEquals('past_due', $tenantA->fresh()->subscription_status);

        $this->assertEquals('suspended', $subB->fresh()->status);
        $this->assertEquals('suspended', $tenantB->fresh()->subscription_status);
        $this->assertEquals('suspended', $tenantB->fresh()->status);

        if ($savedTenantId !== null) {
            app(TenantContext::class)->setTenantId($savedTenantId);
        }
    }
}
