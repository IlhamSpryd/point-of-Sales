<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Core\Context\TenantContext;
use App\Enums\OrderFulfillmentStatus;
use App\Enums\OrderLifecycleStatus;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatusEnum;
use App\Enums\ReturnDispositionEnum;
use App\Enums\StockMovementType;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Finance\Services\FinancialOutboxDispatcherService;
use App\Modules\Integration\Contracts\PaymentGatewayInterface;
use App\Modules\Integration\Models\OfdChannelConfig;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryLedgerService;
use App\Modules\Organization\Models\Device;
use App\Modules\Organization\Models\Role;
use App\Modules\Organization\Models\Setting;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Models\User;
use App\Modules\Sales\Application\Handlers\OnlinePaymentAndWebhookHandler;
use App\Modules\Sales\Models\ChannelOrderLog;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderItem;
use App\Modules\Sales\Models\OrderReturn;
use App\Modules\Sales\Models\Payment;
use App\Modules\Sales\Models\PaymentAttempt;
use App\Modules\Sales\Models\PaymentInbox;
use App\Modules\Sales\Models\PaymentRefund;
use App\Modules\Sales\Models\Shift;
use App\Modules\Sales\Services\ReturnRefundService;
use App\Modules\Subscription\Models\Plan;
use App\Modules\Subscription\Models\ProvisioningRequest;
use App\Modules\Subscription\Models\TenantSubscription;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Midtrans\Config as MidtransSdkConfig;
use RuntimeException;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

class Wave10AWebhookInventoryReturnIntegrityTest extends TestCase
{
    use RefreshDatabase;
    use TwoTenantFixture;

    protected Device $deviceA;

    protected Shift $shiftA;

    protected User $supervisorA;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        app(TenantContext::class)->runWithoutTenant(function () {
            $this->setupTwoTenants();

            $this->deviceA = Device::create([
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA->id,
                'device_name' => 'POS Terminal Alpha',
                'device_code' => 'POS-ALPHA-W10',
                'device_type' => Device::TYPE_POS_TERMINAL,
                'device_fingerprint' => 'fp-w10-01',
                'status' => Device::STATUS_PAIRED,
                'is_active' => true,
            ]);

            $supervisorRole = Role::withoutTenantScope()->firstOrCreate(
                ['name' => 'Supervisor', 'tenant_id' => $this->tenantA->id],
                ['is_active' => true, 'permissions' => ['can_refund_order', 'can_return_order']]
            );

            $this->supervisorA = User::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Supervisor Wave10',
                'email' => 'spv.w10.'.uniqid().'@test.com',
                'password' => bcrypt('password'),
                'pin_hash' => bcrypt('654321'),
                'role_id' => $supervisorRole->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
            $this->supervisorA->stores()->attach($this->storeA->id, ['tenant_id' => $this->tenantA->id]);

            $this->shiftA = Shift::create([
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA->id,
                'user_id' => $this->userAOwner->id,
                'device_id' => $this->deviceA->id,
                'active_cashier_id' => $this->userAOwner->id,
                'opening_balance' => 1000000,
                'status' => 'open',
                'opened_at' => now(),
            ]);
        });

        app(TenantContext::class)->setTenantId($this->tenantA->id);
        app(TenantContext::class)->setStoreId($this->storeA->id);
    }

    public function test_p1_01_ofd_webhook_fails_closed_without_active_signing_secret(): void
    {
        Config::set('services.webhooks.grabfood_secret', null);
        Config::set('services.webhooks.gofood_secret', null);

        $rawBody = json_encode([
            'id' => 'GF-UNAUTH-001',
            'store_id' => $this->storeA->id,
            'items' => [['id' => 'SKU-1', 'name' => 'Kopi', 'quantity' => 1]],
        ], JSON_THROW_ON_ERROR);

        // Case A: No ofd_channel_configs row -> MUST fail closed (401/403)
        $responseMissing = $this->call(
            'POST',
            '/api/webhooks/grabfood/orders?tenant_id='.$this->tenantA->id.'&store_id='.$this->storeA->id,
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $rawBody
        );
        $this->assertContains($responseMissing->getStatusCode(), [401, 403], 'Missing OFD config must fail closed.');

        // Case B: Active config row with empty signing_secret -> MUST fail closed (401/403)
        OfdChannelConfig::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'provider' => 'grabfood',
            'merchant_id' => 'MERCH-EMPTY',
            'signing_secret' => '',
            'is_active' => true,
        ]);

        $responseEmptySecret = $this->call(
            'POST',
            '/api/webhooks/grabfood/orders?tenant_id='.$this->tenantA->id.'&store_id='.$this->storeA->id,
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $rawBody
        );
        $this->assertContains($responseEmptySecret->getStatusCode(), [401, 403], 'Empty OFD signing_secret must fail closed.');

        // Case C: Inactive config row -> MUST fail closed
        OfdChannelConfig::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'provider' => 'gofood',
            'merchant_id' => 'MERCH-INACTIVE',
            'signing_secret' => encrypt('valid-secret-key'),
            'is_active' => false,
        ]);

        $sig = hash_hmac('sha256', $rawBody, 'valid-secret-key');
        $responseInactive = $this->call(
            'POST',
            '/api/webhooks/gofood/orders?tenant_id='.$this->tenantA->id.'&store_id='.$this->storeA->id,
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_GOJEK_SIGNATURE' => $sig],
            $rawBody
        );
        $this->assertContains($responseInactive->getStatusCode(), [401, 403], 'Inactive OFD channel must fail closed.');

        $this->assertSame(0, ChannelOrderLog::withoutGlobalScopes()->where('tenant_id', $this->tenantA->id)->count());
    }

    public function test_p1_02_api_payment_notification_uses_canonical_pipeline_throttle_inbox_and_subscription(): void
    {
        $route = Route::getRoutes()->getByName('api.payment.notification');
        $this->assertNotNull($route);
        $this->assertContains('throttle:120,1', $route->gatherMiddleware(), '/api/payment/notification must enforce throttle:120,1.');

        Config::set('services.midtrans.server_key', 'test-w10-server-key');

        $enterprisePlan = Plan::firstOrCreate(
            ['code' => 'enterprise'],
            [
                'name' => 'Enterprise Plan',
                'price' => 999000.00,
                'billing_cycle' => 'monthly',
                'is_active' => true,
                'max_branches' => 99,
            ]
        );

        // A. SUB-* subscription webhook on /api/payment/notification settles idempotently
        $this->actingAs($this->userAOwner);
        $orchestrator = app(\App\Modules\Subscription\Application\Services\SubscriptionBillingOrchestrator::class);
        $upgrade = $orchestrator->initiateUpgrade($this->tenantA->id, $enterprisePlan->id, 'annual');
        $subOrderId = (string) $upgrade['order_id'];
        $subGrossAmount = $upgrade['amount']->toDecimal(2);
        $subSignature = hash('sha512', $subOrderId.'200'.$subGrossAmount.'test-w10-server-key');

        $subPayload = [
            'order_id' => $subOrderId,
            'status_code' => '200',
            'gross_amount' => $subGrossAmount,
            'signature_key' => $subSignature,
            'transaction_status' => 'settlement',
            'fraud_status' => 'accept',
            'transaction_id' => 'trx-sub-api-001',
        ];

        $resSub1 = $this->postJson('/api/payment/notification', $subPayload);
        $resSub1->assertStatus(200)->assertJson(['message' => 'ok', 'subscription_settled' => true]);

        $provRequest = ProvisioningRequest::where('result_tenant_id', $this->tenantA->id)
            ->where('plan_id', $enterprisePlan->id)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame(ProvisioningRequest::STATUS_COMPLETED, $provRequest->status);
        $this->assertSame('annual', $provRequest->purchased_billing_cycle);

        $subscription = TenantSubscription::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantA->id)
            ->where('plan_id', $enterprisePlan->id)
            ->where('status', 'active')
            ->first();
        $this->assertNotNull($subscription, '/api/payment/notification must settle SUB-* orders via canonical controller.');

        $resSub2 = $this->postJson('/api/payment/notification', $subPayload);
        $resSub2->assertStatus(200)->assertJson(['message' => 'ok', 'subscription_settled' => true]);

        // B. POS order webhook on /api/payment/notification deduplicates via PaymentInboxDispatcher
        $posOrder = Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->userAOwner->id,
            'shift_id' => $this->shiftA->id,
            'created_by_device_id' => $this->deviceA->id,
            'order_code' => 'POS-API-INBOX-001',
            'idempotency_key' => Str::uuid()->toString(),
            'order_date' => now()->toDateString(),
            'subtotal_amount' => 50000,
            'order_amount' => 50000,
            'order_status' => OrderStatus::Pending,
            'payment_method' => 'qris',
        ]);

        $posSig = hash('sha512', $posOrder->order_code.'20050000.00test-w10-server-key');
        $posPayload = [
            'order_id' => $posOrder->order_code,
            'status_code' => '200',
            'gross_amount' => '50000.00',
            'signature_key' => $posSig,
            'transaction_status' => 'settlement',
            'fraud_status' => 'accept',
            'transaction_id' => 'trx-pos-api-001',
        ];

        $resPos1 = $this->postJson('/api/payment/notification', $posPayload);
        $resPos1->assertStatus(200)->assertJson(['message' => 'ok', 'inbox_status' => 'success']);

        $this->assertDatabaseHas('payment_inbox', [
            'tenant_id' => $this->tenantA->id,
            'idempotency_key' => 'midtrans_trx-pos-api-001_settlement',
            'status' => 'processed',
        ]);

        $resPos2 = $this->postJson('/api/payment/notification', $posPayload);
        $resPos2->assertStatus(200)->assertJson(['message' => 'ok', 'inbox_status' => 'already_processed']);
        $this->assertSame(1, PaymentInbox::withoutGlobalScopes()->where('tenant_id', $this->tenantA->id)->count());
    }

    public function test_p1_02_online_payment_handler_rejects_null_gross_amount_sets_unified_statuses_and_includes_service_charge(): void
    {
        $order = Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->userAOwner->id,
            'shift_id' => $this->shiftA->id,
            'created_by_device_id' => $this->deviceA->id,
            'order_code' => 'POS-W10-NULLGA-01',
            'idempotency_key' => Str::uuid()->toString(),
            'order_date' => now()->toDateString(),
            'subtotal_amount' => 100000,
            'discount_amount' => 0,
            'service_charge_amount' => 5000,
            'tax_amount' => 11550,
            'order_amount' => 116550,
            'order_status' => OrderStatus::Pending,
            'order_lifecycle_status' => OrderLifecycleStatus::Submitted->value,
            'payment_status' => OrderPaymentStatus::AwaitingPayment->value,
            'fulfillment_status' => OrderFulfillmentStatus::Unfulfilled->value,
            'payment_method' => 'qris',
        ]);

        $handler = app(OnlinePaymentAndWebhookHandler::class);

        // 1. Direct updateStatusFromMidtransNotification with null grossAmount MUST fail closed
        $handler->updateStatusFromMidtransNotification($order->order_code, 'settlement', null, null);
        $this->assertSame(OrderStatus::Pending, $order->fresh()->order_status);

        // 2. Webhook with null/missing gross_amount MUST fail closed
        $attempt = PaymentAttempt::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'order_id' => $order->id,
            'device_id' => $this->deviceA->id,
            'shift_id' => $this->shiftA->id,
            'idempotency_key' => 'att-null-ga-1',
            'provider' => 'midtrans',
            'payment_method' => 'qris',
            'provider_order_id' => $order->order_code,
            'amount' => 116550,
            'currency' => 'IDR',
            'status' => PaymentAttemptStatus::PendingProvider,
            'initiated_by_user_id' => $this->userAOwner->id,
        ]);

        $handler->handleMidtransWebhook([
            'order_id' => $order->order_code,
            'transaction_status' => 'settlement',
            'transaction_id' => 'trx-missing-gross',
        ]);
        $this->assertSame(OrderStatus::Pending, $order->fresh()->order_status);
        $this->assertSame(PaymentAttemptStatus::PendingProvider, $attempt->fresh()->status);

        // 3. Valid gross_amount settlement via PaymentAttempt updates unified order lifecycle/payment/fulfillment statuses
        $handler->handleMidtransWebhook([
            'order_id' => $order->order_code,
            'transaction_status' => 'settlement',
            'transaction_id' => 'trx-valid-gross',
            'gross_amount' => '116550.00',
        ]);

        $freshOrder = $order->fresh();
        $this->assertSame(OrderStatus::Paid, $freshOrder->order_status);
        $this->assertSame(OrderLifecycleStatus::Confirmed, $freshOrder->order_lifecycle_status);
        $this->assertSame(OrderPaymentStatus::Paid, $freshOrder->payment_status);
        $this->assertSame(OrderFulfillmentStatus::InPreparation, $freshOrder->fulfillment_status);

        // 4. buildSnapParams includes service_charge_amount explicitly & gross_amount === sum(item_details)
        $snapParams = $handler->buildSnapParams($freshOrder, 100000, 0, 11550, 116550, 'qris', null);
        $itemIds = array_column($snapParams['item_details'], 'id');
        $this->assertContains('SERVICE-CHARGE', $itemIds, 'buildSnapParams must explicitly include SERVICE-CHARGE line item.');
        $sumItems = array_sum(array_map(static fn (array $item): int => (int) $item['price'] * (int) $item['quantity'], $snapParams['item_details']));
        $this->assertSame((int) $snapParams['transaction_details']['gross_amount'], $sumItems);
    }

    public function test_p1_02_reconcile_command_uses_attempt_provider_order_id_and_per_tenant_credentials(): void
    {
        Config::set('services.midtrans.server_key', 'wrong-global-server-key');
        Config::set('services.midtrans.is_production', false);

        app(TenantContext::class)->runAs($this->tenantA->id, $this->storeA->id, function () {
            Setting::set('midtrans_server_key', 'tenant-a-custom-midtrans-key');
            Setting::set('midtrans_is_production', 'false', 'boolean');
        });

        $order = Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->userAOwner->id,
            'shift_id' => $this->shiftA->id,
            'created_by_device_id' => $this->deviceA->id,
            'order_code' => 'ORD-RECON-RETRY-01',
            'idempotency_key' => Str::uuid()->toString(),
            'order_date' => now()->toDateString(),
            'subtotal_amount' => 75000,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'order_amount' => 75000,
            'order_status' => OrderStatus::Pending,
            'order_lifecycle_status' => OrderLifecycleStatus::Submitted->value,
            'payment_status' => OrderPaymentStatus::AwaitingPayment->value,
            'fulfillment_status' => OrderFulfillmentStatus::Unfulfilled->value,
            'payment_method' => 'qris',
            'snap_token' => 'snap_retry_token',
            'created_at' => now()->subMinutes(45),
        ]);

        // Initial attempt expired, retry attempt ATT2 is PendingProvider and settled on Midtrans
        PaymentAttempt::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'order_id' => $order->id,
            'device_id' => $this->deviceA->id,
            'shift_id' => $this->shiftA->id,
            'idempotency_key' => 'recon-att-1',
            'provider' => 'midtrans',
            'payment_method' => 'qris',
            'provider_order_id' => 'ORD-RECON-RETRY-01',
            'amount' => 75000,
            'currency' => 'IDR',
            'status' => PaymentAttemptStatus::Expired,
            'initiated_by_user_id' => $this->userAOwner->id,
        ]);

        $retryAttempt = PaymentAttempt::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'order_id' => $order->id,
            'device_id' => $this->deviceA->id,
            'shift_id' => $this->shiftA->id,
            'idempotency_key' => 'recon-att-2',
            'provider' => 'midtrans',
            'payment_method' => 'qris',
            'provider_order_id' => 'ORD-RECON-RETRY-01-ATT2',
            'amount' => 75000,
            'currency' => 'IDR',
            'status' => PaymentAttemptStatus::PendingProvider,
            'initiated_by_user_id' => $this->userAOwner->id,
        ]);

        $queriedIds = [];
        $observedServerKeys = [];
        $gatewayMock = new class($queriedIds, $observedServerKeys) implements PaymentGatewayInterface
        {
            /** @var array<int, string> */
            public array $queriedIds;

            /** @var array<int, string> */
            public array $observedServerKeys;

            /**
             * @param  array<int, string>  $queriedIds
             * @param  array<int, string>  $observedServerKeys
             */
            public function __construct(array &$queriedIds, array &$observedServerKeys)
            {
                $this->queriedIds = &$queriedIds;
                $this->observedServerKeys = &$observedServerKeys;
            }

            public function createSnapToken(array $params, ?string $orderCode = null): string
            {
                return 'snap-mock';
            }

            public function getTransactionStatus(string $orderId): object
            {
                $this->queriedIds[] = $orderId;
                $this->observedServerKeys[] = (string) MidtransSdkConfig::$serverKey;

                return (object) [
                    'order_id' => $orderId,
                    'transaction_id' => 'trx-att2-settled',
                    'transaction_status' => $orderId === 'ORD-RECON-RETRY-01-ATT2' ? 'settlement' : 'expire',
                    'fraud_status' => 'accept',
                    'gross_amount' => '75000.00',
                ];
            }
        };

        $this->app->instance(PaymentGatewayInterface::class, $gatewayMock);

        $this->artisan('orders:reconcile-midtrans')->assertSuccessful();

        $this->assertContains('ORD-RECON-RETRY-01-ATT2', $queriedIds, 'Reconcile command must query PaymentAttempt.provider_order_id (-ATT2).');
        $this->assertContains('tenant-a-custom-midtrans-key', $observedServerKeys, 'Reconcile command must apply per-tenant Midtrans credentials.');
        $this->assertSame(OrderStatus::Paid, $order->fresh()->order_status, 'Paid -ATT2 order must settle to Paid, not expire.');
        $this->assertSame(PaymentAttemptStatus::Succeeded, $retryAttempt->fresh()->status);
    }

    public function test_p1_03_inventory_ledger_initializes_new_branch_at_zero_and_handles_per_item_batch_idempotency(): void
    {
        $category = Category::create([
            'tenant_id' => $this->tenantA->id,
            'category_name' => 'Beans W10',
        ]);

        // Product has legacy global stock = 500 from Branch A
        $product = Product::create([
            'tenant_id' => $this->tenantA->id,
            'category_id' => $category->id,
            'product_name' => 'Gayo Reserve 1kg',
            'product_price' => 250000,
        ]);
        \Illuminate\Support\Facades\DB::table('products')
            ->where('id', $product->id)
            ->update(['stock' => 500]);

        $branchB = Store::withoutTenantScope()->create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Branch B Empty',
            'code' => 'BR-B-EMPTY',
            'is_active' => true,
        ]);

        // Simulate a newly added branch whose balance row has not yet been created while products.stock = 500
        \Illuminate\Support\Facades\DB::table('product_stock_balances')
            ->where('tenant_id', $this->tenantA->id)
            ->where('store_id', $branchB->id)
            ->where('product_id', $product->id)
            ->delete();

        /** @var InventoryLedgerService $ledger */
        $ledger = app(InventoryLedgerService::class);

        // 1. Receiving 10 units in brand-new Branch B must start from 0 -> 10 (NOT clone 500 -> 510!)
        $ledger->recordMovement(
            tenantId: $this->tenantA->id,
            storeId: $branchB->id,
            itemType: 'product',
            itemId: $product->id,
            type: StockMovementType::GoodsReceipt,
            quantityDelta: '10',
            unitCost: '150000',
            reason: 'Initial Branch B stock receive',
            idempotencyKey: 'init-branch-b-10'
        );

        $branchBBalance = StockBalance::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantA->id)
            ->where('store_id', $branchB->id)
            ->where('product_id', $product->id)
            ->firstOrFail();

        $this->assertEquals(10.0, (float) $branchBBalance->quantity, 'New branch balance MUST initialize at 0 before delta, not clone products.stock (500).');

        // 2. Batch with duplicate 1st idempotency_key must NOT skip 2nd valid item
        $product2 = Product::create([
            'tenant_id' => $this->tenantA->id,
            'category_id' => $category->id,
            'product_name' => 'Matcha Powder',
            'product_price' => 120000,
            'stock' => 0,
        ]);

        $batchResults = $ledger->recordBatchMovements($this->tenantA->id, [
            [
                'store_id' => $branchB->id,
                'item_type' => 'product',
                'item_id' => $product->id,
                'type' => StockMovementType::GoodsReceipt,
                'quantity_delta' => '10',
                'unit_cost' => '150000',
                'reason' => 'Duplicate retry item 1',
                'idempotency_key' => 'init-branch-b-10',
            ],
            [
                'store_id' => $branchB->id,
                'item_type' => 'product',
                'item_id' => $product2->id,
                'type' => StockMovementType::GoodsReceipt,
                'quantity_delta' => '25',
                'unit_cost' => '80000',
                'reason' => 'Fresh batch item 2',
                'idempotency_key' => 'fresh-branch-b-25',
            ],
        ]);

        $this->assertCount(2, $batchResults);
        $reloadedBranchB = StockBalance::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantA->id)
            ->where('store_id', $branchB->id)
            ->where('product_id', $product->id)
            ->firstOrFail();
        $this->assertEquals(10.0, (float) $reloadedBranchB->quantity, 'Duplicate item 1 must not double-add stock.');

        $product2Balance = StockBalance::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantA->id)
            ->where('store_id', $branchB->id)
            ->where('product_id', $product2->id)
            ->first();
        $this->assertNotNull($product2Balance, 'Item 2 in batch MUST be recorded even when item 1 already existed.');
        $this->assertEquals(25.0, (float) $product2Balance->quantity);
        $this->assertSame(1, StockMovement::withoutGlobalScopes()->where('idempotency_key', 'fresh-branch-b-25')->count());
    }

    public function test_p1_03_return_refund_enforces_shift_store_allocation_cap_gift_card_and_outbox_atomicity(): void
    {
        $category = Category::create([
            'tenant_id' => $this->tenantA->id,
            'category_name' => 'Merchandise W10',
        ]);
        $product = Product::create([
            'tenant_id' => $this->tenantA->id,
            'category_id' => $category->id,
            'product_name' => 'Ceramic Cup',
            'product_price' => 50000,
            'stock' => 20,
        ]);

        $order = Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->userAOwner->id,
            'shift_id' => $this->shiftA->id,
            'created_by_device_id' => $this->deviceA->id,
            'order_code' => 'ORD-W10-RET-01',
            'idempotency_key' => Str::uuid()->toString(),
            'order_date' => now()->toDateString(),
            'order_type' => 'take_away',
            'order_status' => OrderStatus::Paid,
            'payment_method' => 'cash',
            'subtotal_amount' => 100000,
            'order_amount' => 100000,
        ]);

        $item1 = OrderItem::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'order_id' => $order->id,
            'product_id' => $product->id,
            'qty' => 2,
            'order_price' => 50000,
            'order_subtotal' => 100000,
        ]);

        $payment = Payment::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'order_id' => $order->id,
            'shift_id' => $this->shiftA->id,
            'device_id' => $this->deviceA->id,
            'payment_method' => PaymentMethod::Cash,
            'amount' => 100000,
            'status' => PaymentStatusEnum::Captured,
            'captured_at' => now(),
            'processed_by' => $this->userAOwner->id,
            'idempotency_key' => 'pay-w10-ret-01',
        ]);

        /** @var ReturnRefundService $returnService */
        $returnService = app(ReturnRefundService::class);

        // Case A: Cross-store Shift must be rejected
        $otherStore = Store::withoutTenantScope()->create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Store A2 Other',
            'code' => 'STR-A2',
            'is_active' => true,
        ]);
        $otherShift = app(TenantContext::class)->runAs($this->tenantA->id, $otherStore->id, function () use ($otherStore) {
            $otherDevice = Device::create([
                'tenant_id' => $this->tenantA->id,
                'store_id' => $otherStore->id,
                'device_name' => 'POS Terminal Beta',
                'device_code' => 'POS-BETA-W10',
                'device_type' => Device::TYPE_POS_TERMINAL,
                'device_fingerprint' => 'fp-w10-other',
                'status' => Device::STATUS_PAIRED,
                'is_active' => true,
            ]);

            return Shift::create([
                'tenant_id' => $this->tenantA->id,
                'store_id' => $otherStore->id,
                'user_id' => $this->userAOwner->id,
                'device_id' => $otherDevice->id,
                'active_cashier_id' => $this->userAOwner->id,
                'opening_balance' => 500000,
                'status' => 'open',
                'opened_at' => now(),
            ]);
        });

        try {
            $returnService->createReturnAndRefund(
                order: $order,
                returnItemsWithDisposition: [['order_item_id' => $item1->id, 'quantity' => 1, 'disposition' => ReturnDispositionEnum::Restock]],
                refundAllocations: [['payment_id' => $payment->id, 'amount' => 50000, 'refund_method' => 'cash']],
                shiftId: $otherShift->id,
                deviceId: $this->deviceA->id,
                actorUserId: $this->supervisorA->id,
                supervisorPin: '654321',
                reason: 'Cross store shift test',
                idempotencyKey: 'ret-cross-shift-01'
            );
            $this->fail('Cross-store shift MUST be rejected.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException|DomainException) {
            $this->addToAssertionCount(1);
        }

        // Case B: Over-allocated refund (returning 1 item worth 50k, asking 80k refund on 100k payment) MUST reject
        try {
            $returnService->createReturnAndRefund(
                order: $order,
                returnItemsWithDisposition: [['order_item_id' => $item1->id, 'quantity' => 1, 'disposition' => ReturnDispositionEnum::Restock]],
                refundAllocations: [['payment_id' => $payment->id, 'amount' => 80000, 'refund_method' => 'cash']],
                shiftId: $this->shiftA->id,
                deviceId: $this->deviceA->id,
                actorUserId: $this->supervisorA->id,
                supervisorPin: '654321',
                reason: 'Over-allocation exploit',
                idempotencyKey: 'ret-overalloc-01'
            );
            $this->fail('Refund allocation > returned item value MUST be rejected.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('exceed', strtolower($e->getMessage()));
        }

        // Case C: Gift card refund without card_number / reference_number MUST fail before commit
        try {
            $returnService->createReturnAndRefund(
                order: $order,
                returnItemsWithDisposition: [['order_item_id' => $item1->id, 'quantity' => 1, 'disposition' => ReturnDispositionEnum::Restock]],
                refundAllocations: [['payment_id' => $payment->id, 'amount' => 50000, 'refund_method' => 'gift_card']],
                shiftId: $this->shiftA->id,
                deviceId: $this->deviceA->id,
                actorUserId: $this->supervisorA->id,
                supervisorPin: '654321',
                reason: 'Missing gift card number',
                idempotencyKey: 'ret-missing-gc-01'
            );
            $this->fail('Gift card refund without cardNumber MUST be rejected before commit.');
        } catch (ValidationException|DomainException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(0, OrderReturn::withoutGlobalScopes()->where('order_id', $order->id)->count());

        // Case D: Exception inside FinancialOutboxDispatcherService MUST roll back transaction atomically (never swallow)
        $failingOutbox = new class extends FinancialOutboxDispatcherService
        {
            public function dispatchSalesReturnRefunded(\App\Modules\Sales\Contracts\OrderReturnReaderInterface $orderReturn): \App\Modules\Finance\Models\FinancialEventOutbox
            {
                throw new RuntimeException('Simulated Outbox Failure');
            }
        };
        $this->app->instance(FinancialOutboxDispatcherService::class, $failingOutbox);

        try {
            app(ReturnRefundService::class)->createReturnAndRefund(
                order: $order,
                returnItemsWithDisposition: [['order_item_id' => $item1->id, 'quantity' => 1, 'disposition' => ReturnDispositionEnum::Restock]],
                refundAllocations: [['payment_id' => $payment->id, 'amount' => 50000, 'refund_method' => 'cash']],
                shiftId: $this->shiftA->id,
                deviceId: $this->deviceA->id,
                actorUserId: $this->supervisorA->id,
                supervisorPin: '654321',
                reason: 'Outbox rollback test',
                idempotencyKey: 'ret-outbox-fail-01'
            );
            $this->fail('FinancialOutboxDispatcherService failure MUST propagate and roll back OrderReturn.');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated Outbox Failure', $e->getMessage());
        }

        $this->assertSame(0, OrderReturn::withoutGlobalScopes()->where('order_id', $order->id)->count(), 'OrderReturn MUST roll back on outbox dispatch failure.');
        $this->assertSame(0, PaymentRefund::withoutGlobalScopes()->where('order_id', $order->id)->count());
    }
}
