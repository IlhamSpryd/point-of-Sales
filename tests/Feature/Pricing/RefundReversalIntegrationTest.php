<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use App\Enums\LoyaltyLedgerTypeEnum;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatusEnum;
use App\Enums\ReturnDispositionEnum;
use App\Models\CashDrawerMovement;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Customers\Enums\StoreCreditLedgerTypeEnum;
use App\Modules\Customers\Enums\VoucherDiscountTypeEnum;
use App\Modules\Customers\Enums\VoucherStatusEnum;
use App\Models\LoyaltyTier;
use App\Modules\Customers\Models\StoreCreditAccount;
use App\Modules\Customers\Models\StoreCreditLedger;
use App\Modules\Customers\Models\Voucher;
use App\Services\CashierPinService;
use App\Modules\Customers\Services\LoyaltyService;
use App\Modules\Customers\Services\StoreCreditService;
use App\Modules\Customers\Services\VoucherService;
use App\Modules\Pricing\Models\PromotionReservation;
use App\Modules\Sales\Services\ReturnRefundService;
use App\Modules\Sales\Services\TransactionService;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RefundReversalIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Store $store;
    protected User $supervisor;
    protected User $cashier;
    protected Shift $shift;
    protected Device $device;
    protected Customer $customer;
    protected Product $productA;
    protected Product $productB;
    protected ReturnRefundService $returnService;
    protected LoyaltyService $loyaltyService;
    protected VoucherService $voucherService;
    protected StoreCreditService $storeCreditService;
    protected TransactionService $transactionService;

    protected function afterSubclassSetUp(): void
    {
        $this->tenant = Tenant::create([
            'name' => 'Refund Reversal Tenant',
            'domain' => 'refund.pos.test',
            'is_active' => true,
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Store Refund',
            'code' => 'STR-REF',
            'is_active' => true,
        ]);

        app(TenantContext::class)->setStoreId($this->store->id);

        $managerRole = Role::firstOrCreate(
            ['name' => 'Manager', 'tenant_id' => $this->tenant->id],
            ['guard_name' => 'web']
        );

        $cashierRole = Role::firstOrCreate(
            ['name' => 'Cashier', 'tenant_id' => $this->tenant->id],
            ['guard_name' => 'web']
        );

        $this->supervisor = User::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'role_id' => $managerRole->id,
            'name' => 'Supervisor User',
            'email' => 'supervisor@refund.test',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        app(CashierPinService::class)->setPin($this->supervisor, '654321');

        $this->cashier = User::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'role_id' => $cashierRole->id,
            'name' => 'Cashier User',
            'email' => 'cashier@refund.test',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        $this->device = Device::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'device_name' => 'POS Terminal 1',
            'device_code' => 'POS-DEV-REF-01',
            'device_type' => Device::TYPE_POS_TERMINAL,
            'is_active' => true,
        ]);

        $this->shift = Shift::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->cashier->id,
            'device_id' => $this->device->id,
            'opening_balance' => 1000000,
            'opened_at' => now(),
            'status' => 'open',
        ]);

        $this->customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'name' => 'Loyal Customer',
            'email' => 'loyal@refund.test',
            'phone' => '081299887766',
        ]);

        $tier = LoyaltyTier::create([
            'tenant_id' => $this->tenant->id,
            'tier_code' => 'TIER-SLV',
            'name' => 'Silver Tier',
            'min_points' => 0,
            'points_multiplier' => '1.00',
            'is_active' => true,
        ]);

        DB::table('customer_loyalty_accounts')->insert([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'current_tier_id' => $tier->id,
            'current_points' => 0,
            'lifetime_points_earned' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'General Merchandise',
        ]);

        $this->productA = Product::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'category_id' => $category->id,
            'product_code' => 'PRD-REF-01',
            'product_name' => 'Item Alpha',
            'price' => 50000,
            'base_price' => 50000,
            'stock' => 100,
            'is_active' => true,
        ]);

        $this->productB = Product::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'category_id' => $category->id,
            'product_code' => 'PRD-REF-02',
            'product_name' => 'Item Beta',
            'price' => 50000,
            'base_price' => 50000,
            'stock' => 100,
            'is_active' => true,
        ]);

        $this->returnService = app(ReturnRefundService::class);
        $this->loyaltyService = app(LoyaltyService::class);
        $this->voucherService = app(VoucherService::class);
        $this->storeCreditService = app(StoreCreditService::class);
        $this->transactionService = app(TransactionService::class);
    }

    public function test_partial_return_proportionately_reverses_loyalty_points(): void
    {
        // 1. Buat pesanan: 2 x Item Alpha (@ Rp50.000 = Rp100.000)
        $order = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->cashier->id,
            'shift_id' => $this->shift->id,
            'created_by_device_id' => $this->device->id,
            'customer_id' => $this->customer->id,
            'order_code' => 'ORD-REF-PARTIAL',
            'order_date' => now(),
            'idempotency_key' => 'ord-idemp-01',
            'order_status' => OrderStatus::Paid,
            'payment_method' => PaymentMethod::Cash,
            'subtotal_amount' => 100000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'service_charge_amount' => 0,
            'order_amount' => 100000,
            'dpp_amount' => 100000,
            'rounding_adjustment' => 0,
            'cash_received' => 100000,
            'order_change' => 0,
        ]);

        $item1 = OrderItem::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $order->id,
            'product_id' => $this->productA->id,
            'qty' => 2,
            'order_price' => 50000,
            'order_subtotal' => 100000,
            'item_discount_amount' => 0,
            'net_line_total' => 100000,
        ]);

        $payment = Payment::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $order->id,
            'shift_id' => $this->shift->id,
            'device_id' => $this->device->id,
            'payment_method' => PaymentMethod::Cash,
            'amount' => 100000,
            'amount_tendered' => 100000,
            'change_amount' => 0,
            'status' => PaymentStatusEnum::Captured,
            'processed_by' => $this->cashier->id,
            'idempotency_key' => 'pay-ord-partial-01',
        ]);

        // Customer memperoleh 10 poin atas transaksi Rp100.000
        $this->loyaltyService->earnPoints(
            customer: $this->customer,
            points: 10,
            orderId: $order->id,
            reference: "Poin transaksi {$order->order_code}",
            actor: $this->cashier,
            idempotencyKey: 'earn-ord-partial-01'
        );

        $acc = DB::table('customer_loyalty_accounts')->where('customer_id', $this->customer->id)->first();
        $this->assertEquals(10, $acc->current_points);

        // 2. Lakukan Retur Parsial 1 pcs (50% dari pesanan)
        $return = $this->returnService->createReturnAndRefund(
            order: $order,
            returnItemsWithDisposition: [
                [
                    'order_item_id' => $item1->id,
                    'quantity' => 1,
                    'disposition' => ReturnDispositionEnum::Restock,
                ],
            ],
            refundAllocations: [
                [
                    'payment_id' => $payment->id,
                    'amount' => 50000,
                    'refund_method' => 'cash',
                ],
            ],
            shiftId: $this->shift->id,
            deviceId: $this->device->id,
            actorUserId: $this->cashier->id,
            supervisorPin: '654321',
            reason: 'Retur 1 item cacat kemasan',
            idempotencyKey: 'ret-partial-01'
        );

        $this->assertNotNull($return);

        // 3. Verifikasi Reversal Poin 50% = 5 poin terpotong
        $reversalLedger = DB::table('loyalty_ledger')
            ->where('order_id', $order->id)
            ->where('type', LoyaltyLedgerTypeEnum::Reversal->value)
            ->first();

        $this->assertNotNull($reversalLedger);
        $this->assertEquals(-5, (int) $reversalLedger->points);

        $accAfter = DB::table('customer_loyalty_accounts')->where('customer_id', $this->customer->id)->first();
        $this->assertEquals(5, $accAfter->current_points);
    }

    public function test_full_return_reverses_all_loyalty_points_and_restores_voucher(): void
    {
        // 1. Siapkan Voucher Kuota 1
        $voucher = Voucher::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'FULLRET10K',
            'name' => 'Voucher 10K',
            'discount_type' => VoucherDiscountTypeEnum::FIXED_AMOUNT,
            'discount_value' => '10000.00',
            'min_order_amount' => '40000.00',
            'max_uses' => 1,
            'used_count' => 1,
            'status' => VoucherStatusEnum::EXPIRED, // status expired karena max_uses=1 sudah terpakai
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addDays(7),
        ]);

        $order = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->cashier->id,
            'shift_id' => $this->shift->id,
            'created_by_device_id' => $this->device->id,
            'customer_id' => $this->customer->id,
            'order_code' => 'ORD-REF-FULL',
            'order_date' => now(),
            'idempotency_key' => 'ord-idemp-02',
            'order_status' => OrderStatus::Paid,
            'payment_method' => PaymentMethod::Cash,
            'subtotal_amount' => 50000,
            'discount_amount' => 10000,
            'tax_amount' => 0,
            'service_charge_amount' => 0,
            'order_amount' => 40000,
            'dpp_amount' => 40000,
            'rounding_adjustment' => 0,
            'cash_received' => 40000,
            'order_change' => 0,
        ]);

        $item = OrderItem::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $order->id,
            'product_id' => $this->productA->id,
            'qty' => 1,
            'order_price' => 50000,
            'order_subtotal' => 50000,
            'item_discount_amount' => 10000,
            'net_line_total' => 40000,
        ]);

        // Catat reservasi committed untuk voucher pada order
        PromotionReservation::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'voucher_id' => $voucher->id,
            'order_id' => $order->id,
            'cart_uuid' => 'cart-full-01',
            'customer_id' => $this->customer->id,
            'reserved_amount' => 10000,
            'status' => PromotionReservation::STATUS_COMMITTED,
            'expires_at' => now()->addHours(1),
        ]);

        $payment = Payment::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $order->id,
            'shift_id' => $this->shift->id,
            'device_id' => $this->device->id,
            'payment_method' => PaymentMethod::Cash,
            'amount' => 40000,
            'amount_tendered' => 40000,
            'change_amount' => 0,
            'status' => PaymentStatusEnum::Captured,
            'processed_by' => $this->cashier->id,
            'idempotency_key' => 'pay-ord-full-01',
        ]);

        // Customer memperoleh 4 poin atas DPP 40.000
        $this->loyaltyService->earnPoints(
            customer: $this->customer,
            points: 4,
            orderId: $order->id,
            reference: "Poin transaksi {$order->order_code}",
            actor: $this->cashier,
            idempotencyKey: 'earn-ord-full-01'
        );

        $acc = DB::table('customer_loyalty_accounts')->where('customer_id', $this->customer->id)->first();
        $this->assertEquals(4, $acc->current_points);

        // 2. Eksekusi 100% Full Return
        $return = $this->returnService->createReturnAndRefund(
            order: $order,
            returnItemsWithDisposition: [
                [
                    'order_item_id' => $item->id,
                    'quantity' => 1,
                    'disposition' => ReturnDispositionEnum::Restock,
                ],
            ],
            refundAllocations: [
                [
                    'payment_id' => $payment->id,
                    'amount' => 40000,
                    'refund_method' => 'cash',
                ],
            ],
            shiftId: $this->shift->id,
            deviceId: $this->device->id,
            actorUserId: $this->cashier->id,
            supervisorPin: '654321',
            reason: 'Batal beli total',
            idempotencyKey: 'ret-full-01'
        );

        $this->assertNotNull($return);

        // 3. Verifikasi Seluruh Poin Dibalikkan (Saldo 0)
        $accAfter = DB::table('customer_loyalty_accounts')->where('customer_id', $this->customer->id)->first();
        $this->assertEquals(0, $accAfter->current_points);

        // 4. Verifikasi Voucher Kuota Dipulihkan & Status Kembali ACTIVE
        $freshVoucher = $voucher->fresh();
        $this->assertEquals(0, $freshVoucher->used_count);
        $this->assertEquals(VoucherStatusEnum::ACTIVE, $freshVoucher->status);
    }

    public function test_non_cash_tender_can_be_refunded_to_store_credit(): void
    {
        // 1. Buat pesanan dibayar via QRIS / Non-tunai Rp50.000
        $order = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->cashier->id,
            'shift_id' => $this->shift->id,
            'created_by_device_id' => $this->device->id,
            'customer_id' => $this->customer->id,
            'order_code' => 'ORD-REF-SC',
            'order_date' => now(),
            'idempotency_key' => 'ord-idemp-03',
            'order_status' => OrderStatus::Paid,
            'payment_method' => PaymentMethod::Qris,
            'subtotal_amount' => 50000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'service_charge_amount' => 0,
            'order_amount' => 50000,
            'dpp_amount' => 50000,
            'rounding_adjustment' => 0,
            'cash_received' => 50000,
            'order_change' => 0,
        ]);

        $item = OrderItem::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $order->id,
            'product_id' => $this->productA->id,
            'qty' => 1,
            'order_price' => 50000,
            'order_subtotal' => 50000,
            'item_discount_amount' => 0,
            'net_line_total' => 50000,
        ]);

        $payment = Payment::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $order->id,
            'shift_id' => $this->shift->id,
            'device_id' => $this->device->id,
            'payment_method' => PaymentMethod::Qris,
            'amount' => 50000,
            'amount_tendered' => 50000,
            'change_amount' => 0,
            'status' => PaymentStatusEnum::Captured,
            'processed_by' => $this->cashier->id,
            'idempotency_key' => 'pay-ord-sc-01',
        ]);

        // 2. Eksekusi refund dengan target metode 'store_credit'
        $return = $this->returnService->createReturnAndRefund(
            order: $order,
            returnItemsWithDisposition: [
                [
                    'order_item_id' => $item->id,
                    'quantity' => 1,
                    'disposition' => ReturnDispositionEnum::Restock,
                ],
            ],
            refundAllocations: [
                [
                    'payment_id' => $payment->id,
                    'amount' => 50000,
                    'refund_method' => 'store_credit',
                ],
            ],
            shiftId: $this->shift->id,
            deviceId: $this->device->id,
            actorUserId: $this->cashier->id,
            supervisorPin: '654321',
            reason: 'Pengembalian dana non-tunai ke saldo Store Credit',
            idempotencyKey: 'ret-sc-01'
        );

        $this->assertNotNull($return);

        // 3. Verifikasi saldo Store Credit pelanggan bertambah Rp50.000
        $scAccount = StoreCreditAccount::where('customer_id', $this->customer->id)->first();
        $this->assertNotNull($scAccount);
        $this->assertEquals('50000.00', $scAccount->current_balance);

        $scLedger = StoreCreditLedger::where('store_credit_account_id', $scAccount->id)->first();
        $this->assertNotNull($scLedger);
        $this->assertEquals(StoreCreditLedgerTypeEnum::REVERSAL, $scLedger->entry_type);
        $this->assertEquals('50000.00', $scLedger->amount);
    }

    public function test_void_order_reverses_loyalty_points_and_releases_voucher(): void
    {
        // 1. Siapkan Voucher Kuota 1
        $voucher = Voucher::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'VOIDVOUCHER',
            'name' => 'Voucher Void',
            'discount_type' => VoucherDiscountTypeEnum::FIXED_AMOUNT,
            'discount_value' => '10000.00',
            'min_order_amount' => '40000.00',
            'max_uses' => 1,
            'used_count' => 1,
            'status' => VoucherStatusEnum::EXPIRED,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addDays(7),
        ]);

        $order = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->cashier->id,
            'shift_id' => $this->shift->id,
            'created_by_device_id' => $this->device->id,
            'customer_id' => $this->customer->id,
            'order_code' => 'ORD-VOID-TEST',
            'order_date' => now(),
            'idempotency_key' => 'ord-idemp-04',
            'order_status' => OrderStatus::Paid,
            'payment_method' => PaymentMethod::Cash,
            'subtotal_amount' => 50000,
            'discount_amount' => 10000,
            'tax_amount' => 0,
            'service_charge_amount' => 0,
            'order_amount' => 40000,
            'dpp_amount' => 40000,
            'cash_received' => 40000,
            'order_change' => 0,
        ]);

        PromotionReservation::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'voucher_id' => $voucher->id,
            'order_id' => $order->id,
            'cart_uuid' => 'cart-void-01',
            'customer_id' => $this->customer->id,
            'reserved_amount' => 10000,
            'status' => PromotionReservation::STATUS_COMMITTED,
            'expires_at' => now()->addHours(1),
        ]);

        Payment::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $order->id,
            'shift_id' => $this->shift->id,
            'device_id' => $this->device->id,
            'payment_method' => PaymentMethod::Cash,
            'amount' => 40000,
            'amount_tendered' => 40000,
            'change_amount' => 0,
            'status' => PaymentStatusEnum::Captured,
            'processed_by' => $this->cashier->id,
            'idempotency_key' => 'pay-void-01',
        ]);

        $this->loyaltyService->earnPoints(
            customer: $this->customer,
            points: 4,
            orderId: $order->id,
            reference: "Poin transaksi {$order->order_code}",
            actor: $this->cashier,
            idempotencyKey: 'earn-void-01'
        );

        $acc = DB::table('customer_loyalty_accounts')->where('customer_id', $this->customer->id)->first();
        $this->assertEquals(4, $acc->current_points);

        // 2. Batalkan pesanan via voidOrder
        $this->transactionService->voidOrder($order, 'Kasir salah input item', $this->supervisor->id);

        // 3. Verifikasi status order Void
        $this->assertEquals(OrderStatus::Void, $order->fresh()->order_status);

        // 4. Verifikasi poin loyalitas dibalikkan
        $accAfter = DB::table('customer_loyalty_accounts')->where('customer_id', $this->customer->id)->first();
        $this->assertEquals(0, $accAfter->current_points);

        // 5. Verifikasi reservasi dilepaskan dan voucher dipulihkan
        $res = PromotionReservation::where('order_id', $order->id)->first();
        $this->assertEquals(PromotionReservation::STATUS_RELEASED, $res->status);

        $freshVoucher = $voucher->fresh();
        $this->assertEquals(0, $freshVoucher->used_count);
        $this->assertEquals(VoucherStatusEnum::ACTIVE, $freshVoucher->status);
    }
}
