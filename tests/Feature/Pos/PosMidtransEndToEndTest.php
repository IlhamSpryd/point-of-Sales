<?php

declare(strict_types=1);

namespace Tests\Feature\Pos;

use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PreparationStatus;
use App\Livewire\Kasir\CreateOrder;
use App\Livewire\Kasir\OrderHistory;
use App\Livewire\Kds\Board;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\Store;
use App\Models\Table;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CashierPinService;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class PosMidtransEndToEndTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;

    private Store $store;

    private User $cashier;

    private User $supervisor;

    private Device $device;

    private Shift $shift;

    private Category $category;

    private Product $productCoffee;

    private Product $productPastry;

    private Table $table;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        Config::set('services.midtrans.server_key', 'test_midtrans_e2e_server_key');
        Config::set('services.midtrans.client_key', 'test_midtrans_e2e_client_key');
        Config::set('services.midtrans.is_production', false);

        $this->tenant = Tenant::create([
            'name' => 'Apeiron Global POS Tenant',
            'slug' => 'apeiron-pos-e2e-'.uniqid(),
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Apeiron Flagship SCBD',
            'code' => 'SCBD-01',
            'address' => 'Jl. Jend. Sudirman Kav. 52-53, Jakarta Selatan',
            'phone' => '021-5150999',
            'is_active' => true,
        ]);

        app(TenantContext::class)->setStoreId($this->store->id);

        Setting::updateOrCreate(
            ['tenant_id' => $this->tenant->id, 'key' => 'tax_rate'],
            ['value' => '0.10']
        );
        Setting::updateOrCreate(
            ['tenant_id' => $this->tenant->id, 'key' => 'rounding_value'],
            ['value' => '0']
        );
        Setting::updateOrCreate(
            ['tenant_id' => $this->tenant->id, 'key' => 'rounding_behavior'],
            ['value' => 'NONE']
        );

        $roleCashier = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Kasir',
            'permissions' => ['pos.order', 'pos.shift'],
            'is_active' => true,
        ]);

        $roleManager = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Manager',
            'permissions' => ['*'],
            'is_active' => true,
        ]);

        $this->cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'role_id' => $roleCashier->id,
            'name' => 'Nadia Cashier',
            'password' => Hash::make('password123'),
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $this->supervisor = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'role_id' => $roleManager->id,
            'name' => 'Budi Manager',
            'password' => Hash::make('password123'),
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        DB::table('user_stores')->insert([
            ['tenant_id' => $this->tenant->id, 'user_id' => $this->cashier->id, 'store_id' => $this->store->id],
            ['tenant_id' => $this->tenant->id, 'user_id' => $this->supervisor->id, 'store_id' => $this->store->id],
        ]);

        $pinService = app(CashierPinService::class);
        $pinService->setPin($this->cashier, '123456');
        $pinService->setPin($this->supervisor, '654321');

        $this->device = Device::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'device_name' => 'Terminal SCBD #01',
            'device_code' => 'TERM-SCBD-01',
            'device_type' => 'pos_terminal',
            'device_secret' => 'secret-scbd-01',
            'is_active' => true,
        ]);

        $this->shift = Shift::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'device_id' => $this->device->id,
            'user_id' => $this->cashier->id,
            'active_cashier_id' => $this->cashier->id,
            'opening_balance' => 500000,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $this->category = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Signature Coffee & Beverage',
        ]);

        $this->productCoffee = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'Apeiron Spanish Latte',
            'product_price' => 40000,
            'stock' => 25,
            'track_inventory' => true,
            'status' => 'active',
            'is_active' => true,
        ]);

        $this->productPastry = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'Almond Croissant',
            'product_price' => 30000,
            'stock' => 15,
            'track_inventory' => true,
            'status' => 'active',
            'is_active' => true,
        ]);

        DB::table('product_stock_balances')->insertOrIgnore([
            [
                'tenant_id' => $this->tenant->id,
                'store_id' => $this->store->id,
                'product_id' => $this->productCoffee->id,
                'quantity' => 25,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tenant_id' => $this->tenant->id,
                'store_id' => $this->store->id,
                'product_id' => $this->productPastry->id,
                'quantity' => 15,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->table = Table::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'table_name' => 'T-01',
            'capacity' => 4,
            'operational_status' => 'available',
            'is_active' => true,
            'secure_token' => Str::random(32),
        ]);
    }

    private function signMidtransWebhook(string $orderId, string $statusCode, string $grossAmount): string
    {
        return hash('sha512', $orderId.$statusCode.$grossAmount.'test_midtrans_e2e_server_key');
    }

    public function test_cashier_pos_cash_checkout_records_exact_tendered_change_and_renders_dynamic_receipt(): void
    {
        // Subtotal: 2 * 40.000 = 80.000, Tax 10% = 8.000, Total = 88.000
        // Cash Tendered = 100.000 -> Change Due = 12.000
        $component = Livewire::actingAs($this->cashier)
            ->test(CreateOrder::class)
            ->call('setOrderType', 'takeaway')
            ->call('addToCart', $this->productCoffee->id)
            ->call('addToCart', $this->productCoffee->id)
            ->assertSet('subtotal', 80000)
            ->assertSet('taxAmount', 8000)
            ->assertSet('totalAmount', 88000)
            ->call('submitOrder', [
                [
                    'method' => 'cash',
                    'amount' => 88000,
                    'amount_tendered' => 100000,
                    'change_amount' => 12000,
                ],
            ], 'idemp-cash-e2e-001');

        $component->assertDispatched('transaction-success');

        $order = Order::where('idempotency_key', 'idemp-cash-e2e-001')->firstOrFail();
        $this->assertEquals(OrderStatus::Paid, $order->order_status);
        $this->assertEquals(88000, (int) $order->order_amount);
        $this->assertEquals(100000, (int) $order->cash_received);
        $this->assertEquals(12000, (int) $order->order_change);

        $payment = Payment::where('order_id', $order->id)->firstOrFail();
        $this->assertEquals(88000, (int) $payment->amount);
        $this->assertEquals(100000, (int) $payment->amount_tendered);
        $this->assertEquals(12000, (int) $payment->change_amount);

        // Verify inventory decremented from 25 -> 23
        $this->assertEquals(23, (int) $this->productCoffee->fresh()->stock);

        // Verify dynamic thermal receipt view renders store name, address, phone, cash received, and change
        $receiptResponse = $this->actingAs($this->cashier)
            ->get(route('transaction.receipt', ['orderCode' => $order->order_code]));

        $receiptResponse->assertStatus(200)
            ->assertSee('Apeiron Flagship SCBD')
            ->assertSee('Jl. Jend. Sudirman Kav. 52-53, Jakarta Selatan')
            ->assertSee('021-5150999')
            ->assertDontSee('Jl. Dewa No. 16b, Jakarta')
            ->assertSee('100.000')
            ->assertSee('12.000');
    }

    public function test_cashier_pos_manual_discount_and_edc_card_reference_checkout(): void
    {
        // Subtotal: 40.000 + 30.000 = 70.000
        // Manual Discount: 10.000 (with Supervisor PIN 654321) -> Discounted Subtotal = 60.000
        // Tax 10% = 6.000 -> Total Due = 66.000
        $component = Livewire::actingAs($this->cashier)
            ->test(CreateOrder::class)
            ->call('setOrderType', 'takeaway')
            ->call('addToCart', $this->productCoffee->id)
            ->call('addToCart', $this->productPastry->id)
            ->assertSet('subtotal', 70000)
            ->set('manualDiscountAmountInput', 10000)
            ->set('manualDiscountReason', 'VIP Partner Courtesy')
            ->call('applyManualDiscount', '654321')
            ->assertSet('appliedManualDiscount', 10000)
            ->assertSet('discountAmount', 10000)
            ->assertSet('taxAmount', 6000)
            ->assertSet('totalAmount', 66000)
            ->call('submitOrder', [
                [
                    'method' => 'card',
                    'amount' => 66000,
                    'reference_number' => 'BCA-849201',
                    'card_number' => 'BCA-849201',
                ],
            ], 'idemp-card-disc-e2e-002');

        $component->assertHasNoErrors();
        $component->assertDispatched('transaction-success');

        $order = Order::where('idempotency_key', 'idemp-card-disc-e2e-002')->firstOrFail();
        $this->assertEquals(OrderStatus::Paid, $order->order_status);
        $this->assertEquals(70000, (int) $order->subtotal_amount);
        $this->assertEquals(10000, (int) $order->discount_amount);
        $this->assertEquals(6000, (int) $order->tax_amount);
        $this->assertEquals(66000, (int) $order->order_amount);

        $payment = Payment::where('order_id', $order->id)->firstOrFail();
        $this->assertEquals('card', $payment->payment_method->value);
        $this->assertEquals('BCA-849201', $payment->reference_number);
    }

    public function test_cashier_pos_midtrans_gateway_checkout_settles_via_webhook_and_routes_to_kds(): void
    {
        $customer = Customer::forceCreate([
            'tenant_id' => $this->tenant->id,
            'name' => 'Rizky Pratama',
            'phone' => '081299887766',
            'is_active' => true,
        ]);

        // 1. Cashier creates QRIS Midtrans Gateway order in POS
        // Subtotal: 40.000, Tax 10%: 4.000, Total: 44.000
        $component = Livewire::actingAs($this->cashier)
            ->test(CreateOrder::class)
            ->call('setOrderType', 'dine_in')
            ->set('tableId', $this->table->id)
            ->call('selectCustomer', $customer->id, $customer->name)
            ->call('addToCart', $this->productCoffee->id)
            ->assertSet('totalAmount', 44000)
            ->call('submitOrder', [
                [
                    'method' => 'qris',
                    'amount' => 44000,
                    'use_midtrans_gateway' => true,
                ],
            ], 'idemp-midtrans-pos-003', true);

        $component->assertHasNoErrors();
        $component->assertDispatched('midtrans-payment-pending');

        $order = Order::where('idempotency_key', 'idemp-midtrans-pos-003')->firstOrFail();
        $this->assertEquals(OrderStatus::Pending, $order->order_status);
        $this->assertNotNull($order->snap_token);
        $this->assertStringStartsWith('snap-sandbox-', (string) $order->snap_token);

        // Verify PaymentAttempt created in PendingProvider state
        $attempt = PaymentAttempt::where('order_id', $order->id)->firstOrFail();
        $this->assertEquals('midtrans', $attempt->provider);
        $this->assertEquals(PaymentAttemptStatus::PendingProvider, $attempt->status);
        $this->assertEquals($order->order_code, $attempt->provider_order_id);
        $this->assertEquals(44000, (int) $attempt->amount);

        // While Pending, KDS should NOT show the unpaid order yet (Zero-Trust KDS gate)
        $kdsBefore = Livewire::actingAs($this->supervisor)->test(Board::class);
        $this->assertCount(0, $kdsBefore->get('pendingItems'));

        // 2. Midtrans sends SHA-512 signed settlement webhook to /midtrans/notification
        $grossStr = '44000.00';
        $signature = $this->signMidtransWebhook($order->order_code, '200', $grossStr);

        $webhookResponse = $this->postJson(route('midtrans.notification'), [
            'order_id' => $order->order_code,
            'status_code' => '200',
            'gross_amount' => $grossStr,
            'signature_key' => $signature,
            'transaction_status' => 'settlement',
            'transaction_id' => 'MID-TRX-SCBD-99001',
            'fraud_status' => 'accept',
        ]);

        $webhookResponse->assertStatus(200);

        // 3. Verify Order, PaymentAttempt, and Payment ledger transitioned to Paid / Succeeded / Captured
        $order->refresh();
        $attempt->refresh();

        $this->assertEquals(OrderStatus::Paid, $order->order_status);
        $this->assertEquals(PaymentAttemptStatus::Succeeded, $attempt->status);
        $this->assertEquals('MID-TRX-SCBD-99001', $attempt->provider_transaction_id);

        $payment = Payment::where('order_id', $order->id)->firstOrFail();
        $this->assertEquals(44000, (int) $payment->amount);
        $this->assertEquals('MID-TRX-SCBD-99001', $payment->reference_number);

        // 4. Verify KDS Board now displays the paid order item for preparation
        $kdsAfter = Livewire::actingAs($this->supervisor)->test(Board::class);
        $pendingItems = $kdsAfter->get('pendingItems');
        $this->assertCount(1, $pendingItems);
        $this->assertEquals($order->id, $pendingItems->first()->order_id);

        // Barista/Manager claims and marks item ready in KDS
        $orderItemId = (int) $pendingItems->first()->id;
        $kdsAfter->call('claim', $orderItemId);
        $this->assertEquals(PreparationStatus::Brewing, $order->orderItems()->first()->preparation_status);

        $kdsAfter->call('markReady', $orderItemId);
        $this->assertEquals(PreparationStatus::Ready, $order->orderItems()->first()->preparation_status);
    }

    public function test_midtrans_active_status_sync_and_expired_order_stock_restoration(): void
    {
        $initialStock = (int) $this->productPastry->stock; // 15

        // 1. Create Midtrans order in POS for 2 Almond Croissants (2 * 30.000 = 60.000 + 6.000 tax = 66.000)
        $pos = Livewire::actingAs($this->cashier)
            ->test(CreateOrder::class)
            ->call('setOrderType', 'takeaway')
            ->call('addToCart', $this->productPastry->id)
            ->call('addToCart', $this->productPastry->id)
            ->call('submitOrder', [
                [
                    'method' => 'qris',
                    'amount' => 66000,
                    'use_midtrans_gateway' => true,
                ],
            ], 'idemp-midtrans-sync-004', true);

        $order = Order::where('idempotency_key', 'idemp-midtrans-sync-004')->firstOrFail();
        $this->assertEquals(OrderStatus::Pending, $order->order_status);
        $this->assertEquals($initialStock - 2, (int) $this->productPastry->fresh()->stock);

        // 2. Simulate Midtrans Status API returning 'settlement' when cashier clicks "Verify Payment Status"
        Config::set('services.midtrans.mock_status_response', [
            'order_id' => $order->order_code,
            'transaction_id' => 'MID-SYNC-778899',
            'transaction_status' => 'settlement',
            'fraud_status' => 'accept',
            'gross_amount' => '66000.00',
        ]);

        $pos->call('verifyMidtransPayment', $order->order_code)
            ->assertDispatched('transaction-success');

        $this->assertEquals(OrderStatus::Paid, $order->fresh()->order_status);

        // 3. Now create a second Midtrans order that expires and verify stock is restored automatically
        $stockBeforeSecond = (int) $this->productPastry->fresh()->stock;

        Livewire::actingAs($this->cashier)
            ->test(CreateOrder::class)
            ->call('setOrderType', 'takeaway')
            ->call('addToCart', $this->productPastry->id)
            ->call('submitOrder', [
                [
                    'method' => 'qris',
                    'amount' => 33000,
                    'use_midtrans_gateway' => true,
                ],
            ], 'idemp-midtrans-exp-005', true);

        $expiredOrder = Order::where('idempotency_key', 'idemp-midtrans-exp-005')->firstOrFail();
        $this->assertEquals($stockBeforeSecond - 1, (int) $this->productPastry->fresh()->stock);

        Config::set('services.midtrans.mock_status_response', [
            'order_id' => $expiredOrder->order_code,
            'transaction_id' => 'MID-EXP-0001',
            'transaction_status' => 'expire',
            'fraud_status' => 'accept',
            'gross_amount' => '33000.00',
        ]);

        Livewire::actingAs($this->cashier)
            ->test(OrderHistory::class)
            ->call('syncMidtransStatus', $expiredOrder->id);

        $this->assertEquals(OrderStatus::Expired, $expiredOrder->fresh()->order_status);
        $this->assertEquals($stockBeforeSecond, (int) $this->productPastry->fresh()->stock);

        Config::set('services.midtrans.mock_status_response', null);
    }

    public function test_cashier_pos_split_payment_multi_leg_checkout_and_receipt(): void
    {
        // Subtotal: 40.000 + 30.000 = 70.000, Tax 10% = 7.000, Total = 77.000
        // Leg 1: Cash 40.000 (Tendered 50.000, Change 10.000)
        // Leg 2: Card 37.000 (Ref MANDIRI-554433)
        $component = Livewire::actingAs($this->cashier)
            ->test(CreateOrder::class)
            ->call('setOrderType', 'takeaway')
            ->call('addToCart', $this->productCoffee->id)
            ->call('addToCart', $this->productPastry->id)
            ->assertSet('totalAmount', 77000)
            ->call('submitOrder', [
                [
                    'method' => 'cash',
                    'amount' => 40000,
                    'amount_tendered' => 50000,
                    'change_amount' => 10000,
                ],
                [
                    'method' => 'card',
                    'amount' => 37000,
                    'reference_number' => 'MANDIRI-554433',
                ],
            ], 'idemp-split-e2e-006');

        $component->assertHasNoErrors();
        $component->assertDispatched('transaction-success');

        $order = Order::where('idempotency_key', 'idemp-split-e2e-006')->firstOrFail();
        $this->assertEquals(OrderStatus::Paid, $order->order_status);
        $this->assertEquals(50000, (int) $order->cash_received);
        $this->assertEquals(10000, (int) $order->order_change);
        $this->assertCount(2, $order->payments);

        $receiptResponse = $this->actingAs($this->cashier)
            ->get(route('transaction.receipt', ['orderCode' => $order->order_code]));

        $receiptResponse->assertStatus(200)
            ->assertSee('MANDIRI-554433')
            ->assertSee('50.000')
            ->assertSee('10.000');
    }

    public function test_cashier_midtrans_handles_qris_customer_imposed_fee_surcharge_and_settles(): void
    {
        $component = Livewire::actingAs($this->cashier)
            ->test(CreateOrder::class)
            ->call('setOrderType', 'takeaway')
            ->call('addToCart', $this->productCoffee->id)
            ->assertSet('totalAmount', 44000)
            ->call('submitOrder', [
                [
                    'method' => 'qris',
                    'amount' => 44000,
                    'use_midtrans_gateway' => true,
                ],
            ], 'idemp-midtrans-qris-fee-001', true);

        $component->assertDispatched('midtrans-payment-pending');
        $order = Order::where('idempotency_key', 'idemp-midtrans-qris-fee-001')->firstOrFail();
        $this->assertEquals(OrderStatus::Pending, $order->order_status);

        // Simulasi respon Midtrans saat QRIS MDR / Convenience fee dibebankan ke customer
        // gross_amount = 44308.00, metadata.extra_info.gross_amount_info.original_amount = 44000
        Config::set('services.midtrans.mock_status_response', [
            'status_code' => '200',
            'transaction_id' => 'MID-QRIS-FEE-9988',
            'transaction_status' => 'settlement',
            'fraud_status' => 'accept',
            'gross_amount' => '44308.00',
            'metadata' => [
                'extra_info' => [
                    'gross_amount_info' => [
                        'original_amount' => '44000',
                        'gross_amount' => '44308',
                        'customer_imposed_payment_fee' => '308',
                    ],
                ],
            ],
        ]);

        $component->call('verifyMidtransPayment', $order->order_code);

        $component->assertDispatched('transaction-success');
        $this->assertEquals(OrderStatus::Paid, $order->fresh()->order_status);

        $payment = Payment::where('order_id', $order->id)->firstOrFail();
        $this->assertEquals(44000, (int) $payment->amount);
        $this->assertEquals('MID-QRIS-FEE-9988', $payment->reference_number);

        Config::set('services.midtrans.mock_status_response', null);
    }

    public function test_cashier_can_checkout_fast_cash_exact_in_single_tap(): void
    {
        $component = Livewire::actingAs($this->cashier)
            ->test(CreateOrder::class)
            ->call('addToCart', $this->productCoffee->id)
            ->assertSet('totalAmount', 44000)
            ->call('fastCashPay', 0); // 0 = Exact cash

        $component->assertDispatched('transaction-success');
        $component->assertSet('cart', []);

        $order = Order::where('tenant_id', $this->tenant->id)->latest('id')->firstOrFail();
        $this->assertEquals(OrderStatus::Paid, $order->order_status);
        $this->assertEquals(44000, (int) $order->order_amount);
        $this->assertEquals(44000, (int) $order->cash_received);
        $this->assertEquals(0, (int) $order->order_change);

        $payment = Payment::where('order_id', $order->id)->firstOrFail();
        $this->assertEquals(44000, (int) $payment->amount);
        $this->assertEquals('cash', $payment->payment_method->value);
    }

    public function test_cashier_can_checkout_fast_cash_with_larger_tendered_amount(): void
    {
        $component = Livewire::actingAs($this->cashier)
            ->test(CreateOrder::class)
            ->call('addToCart', $this->productCoffee->id)
            ->assertSet('totalAmount', 44000)
            ->call('fastCashPay', 50000); // 50.000 cash

        $component->assertDispatched('transaction-success');

        $order = Order::where('tenant_id', $this->tenant->id)->latest('id')->firstOrFail();
        $this->assertEquals(OrderStatus::Paid, $order->order_status);
        $this->assertEquals(50000, (int) $order->cash_received);
        $this->assertEquals(6000, (int) $order->order_change);
    }

    public function test_cashier_can_scan_barcode_to_add_product_instantly(): void
    {
        $this->productCoffee->update(['barcode' => '899123456789']);

        $component = Livewire::actingAs($this->cashier)
            ->test(CreateOrder::class)
            ->call('scanBarcode', '899123456789')
            ->assertDispatched('toast');

        $this->assertCount(1, $component->get('cart'));
        $this->assertEquals($this->productCoffee->id, $component->get('cart')[0]['product_id']);
    }

    public function test_cashier_store_bank_accounts_and_edc_terminals_are_dynamic(): void
    {
        $component = Livewire::actingAs($this->cashier)
            ->test(CreateOrder::class);

        $bankAccounts = $component->get('storeBankAccounts');
        $edcTerminals = $component->get('storeEdcTerminals');

        $this->assertNotEmpty($bankAccounts);
        $this->assertNotEmpty($edcTerminals);
    }
}

