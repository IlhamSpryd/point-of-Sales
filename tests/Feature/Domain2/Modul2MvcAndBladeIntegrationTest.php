<?php

declare(strict_types=1);

namespace Tests\Feature\Domain2;

use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatusEnum;
use App\Livewire\DiscountManager;
use App\Livewire\Kasir\CreateOrder;
use App\Livewire\Kasir\OrderHistory;
use App\Livewire\ShiftManager;
use App\Models\Category;
use App\Models\Device;
use App\Models\Discount;
use App\Models\EodSettlement;
use App\Models\Ingredient;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentRefund;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CashierPinService;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class Modul2MvcAndBladeIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Store $store;

    protected Device $device;

    protected Role $ownerRole;

    protected Role $managerRole;

    protected Role $supervisorRole;

    protected Role $cashierRole;

    protected User $owner;

    protected User $manager;

    protected User $supervisor;

    protected User $cashier;

    protected User $cashier2;

    protected Category $category;

    protected Product $retailProduct;

    protected Product $recipeProduct;

    protected Shift $openShift;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        app(TenantContext::class)->clear();

        $this->tenant = Tenant::create([
            'name' => 'Apeiron Integration Tenant',
            'slug' => 'apeiron-integration-tenant',
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'onboarding_completed_at' => now(),
        ]);

        $context = app(TenantContext::class);
        $context->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cabang Utama MVC',
            'is_active' => true,
        ]);
        $context->setStoreId($this->store->id);

        $this->device = Device::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'device_name' => 'POS Terminal 01',
            'device_code' => 'DEV-MVC-01',
            'device_type' => 'pos_terminal',
            'device_token' => 'token-mvc-01',
            'is_active' => true,
            'status' => 'active',
        ]);

        $this->ownerRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Owner',
            'is_admin' => true,
            'permissions' => ['*'],
        ]);

        $this->managerRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Manager',
            'permissions' => ['*'],
        ]);

        $this->supervisorRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Supervisor',
            'permissions' => ['orders.*', 'shifts.*', 'can_void_order', 'can_refund_order'],
        ]);

        $this->cashierRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Kasir',
            'permissions' => ['orders.create', 'orders.view'],
        ]);

        $pinService = app(CashierPinService::class);

        $this->owner = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->ownerRole->id,
            'name' => 'Pak Owner',
        ]);
        $pinService->setPin($this->owner, '123456');

        $this->manager = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->managerRole->id,
            'name' => 'Bu Manager',
        ]);
        $pinService->setPin($this->manager, '654321');

        $this->supervisor = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->supervisorRole->id,
            'name' => 'Mas Supervisor',
        ]);
        $pinService->setPin($this->supervisor, '112233');

        $this->cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->cashierRole->id,
            'name' => 'Mbak Kasir 1',
        ]);
        $pinService->setPin($this->cashier, '111111');

        $this->cashier2 = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->cashierRole->id,
            'name' => 'Mas Kasir 2',
        ]);
        $pinService->setPin($this->cashier2, '222222');

        foreach ([$this->owner, $this->manager, $this->supervisor, $this->cashier, $this->cashier2] as $u) {
            DB::table('user_stores')->insert([
                'tenant_id' => $this->tenant->id,
                'user_id' => $u->id,
                'store_id' => $this->store->id,
            ]);
        }

        $this->category = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Retail & F&B',
        ]);

        $this->retailProduct = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'Kopi Kemasan 250g',
            'product_price' => 50000,
            'stock' => 100,
            'is_active' => true,
        ]);

        $this->recipeProduct = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'Caramel Latte Panas',
            'product_price' => 35000,
            'stock' => 50,
            'is_active' => true,
        ]);

        // Berikan ingredient ke recipeProduct agar menjadi item F&B olahan (larangan restock)
        $ingredient = Ingredient::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Susu Segar',
            'unit' => 'ml',
            'cost_per_unit' => 20,
        ]);
        $this->recipeProduct->ingredients()->attach($ingredient->id, ['quantity_required' => 200]);

        $this->openShift = Shift::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'device_id' => $this->device->id,
            'user_id' => $this->cashier->id,
            'shift_number' => 'SH-MVC-001',
            'status' => 'open',
            'opening_balance' => 200000,
            'opened_at' => now(),
        ]);
    }

    /**
     * Test 1: HTTP 200 rendering pada seluruh halaman MVC Modul 2 tanpa Blade missing variable exception.
     */
    public function test_all_modul2_pages_render_http_200_without_blade_errors(): void
    {
        $this->actingAs($this->owner);
        session(['active_store_id' => $this->store->id]);

        $order = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->cashier->id,
            'created_by_device_id' => $this->device->id,
            'shift_id' => $this->openShift->id,
            'order_code' => 'ORD-TEST-BLADE-01',
            'order_status' => OrderStatus::Paid,
            'payment_method' => PaymentMethod::Cash,
            'order_date' => now()->toDateString(),
            'subtotal_amount' => 50000,
            'discount_amount' => 5000,
            'tax_amount' => 4500,
            'service_charge_amount' => 2500,
            'order_amount' => 52000,
            'cash_received' => 60000,
            'order_change' => 8000,
            'print_count' => 1,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        OrderItem::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $order->id,
            'product_id' => $this->retailProduct->id,
            'qty' => 1,
            'order_price' => 50000,
            'order_subtotal' => 50000,
        ]);

        Payment::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $order->id,
            'shift_id' => $this->openShift->id,
            'payment_method' => PaymentMethod::Cash,
            'amount' => 52000,
            'status' => PaymentStatusEnum::Captured,
            'captured_at' => now(),
            'processed_by' => $this->cashier->id,
            'idempotency_key' => 'pay-blade-01',
        ]);

        // 1. Kasir POS (/transaction)
        $resKasir = $this->get(route('transaction.create'));
        $resKasir->assertOk();
        $resKasir->assertSeeLivewire('kasir.create-order');

        // 2. Riwayat Pesanan (/orders)
        $resOrders = $this->get(route('orders.index'));
        $resOrders->assertOk();
        $resOrders->assertSeeLivewire('kasir.order-history');

        // 3. Detail Pesanan (/orders/{orderNumber})
        $resShow = $this->get(route('orders.show', $order->order_code));
        $resShow->assertOk();
        $resShow->assertSee($order->order_code);
        $resShow->assertSee('Item Pesanan');

        // 4. Shift Management (/shifts)
        $resShifts = $this->get(route('shifts.index'));
        $resShifts->assertOk();
        $resShifts->assertSeeLivewire('shift-manager');

        // 5. Diskon & Promo (/discounts)
        $resDiscounts = $this->get(route('discounts.index'));
        $resDiscounts->assertOk();
        $resDiscounts->assertSeeLivewire('discount-manager');

        // 6. Laporan Penjualan (/reports/sales)
        $resSales = $this->get(route('reports.sales'));
        $resSales->assertOk();
        $resSales->assertSee('Gross Sales');
        $resSales->assertSee('Net Sales');

        // 7. Struk Cetakan Pertama & Struk Cetak Ulang (Reprint Watermark)
        $resReceipt1 = $this->get(route('transaction.receipt', ['orderCode' => $order->order_code]));
        $resReceipt1->assertOk();
        $resReceipt1->assertSee($order->order_code);

        // Cetak ulang struk (is_reprint=1)
        $resReceiptReprint = $this->get(route('transaction.receipt', ['orderCode' => $order->order_code, 'is_reprint' => 1]));
        $resReceiptReprint->assertOk();
        $resReceiptReprint->assertSee('*** COPY / CETAK ULANG #');
    }

    /**
     * Test 2: Livewire ShiftManager - Handover, Cash Movement & Force Close.
     */
    public function test_shift_manager_handover_cash_movement_and_force_close(): void
    {
        Livewire::actingAs($this->cashier)
            ->test(ShiftManager::class)
            // 1. Catat Cash Movement (Cash-In)
            ->set('movementCategory', 'restock_change')
            ->set('movementAmount', '50000')
            ->set('movementReason', 'Tambah uang kembalian pecahan kecil')
            ->call('recordCashMovement')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('cash_drawer_movements', [
            'shift_id' => $this->openShift->id,
            'type' => 'cash_in',
            'amount' => 50000,
        ]);

        // 2. Inisiasi Handover ke kasir penerima
        Livewire::actingAs($this->cashier)
            ->test(ShiftManager::class)
            ->set('handoverShiftId', $this->openShift->id)
            ->set('toUserId', $this->cashier2->id)
            ->set('countedCashAtHandover', '250000')
            ->set('handoverNotes', 'Pergantian shift sore')
            ->set('handoverCashierPin', '111111')
            ->call('initiateHandover')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('shift_handovers', [
            'shift_id' => $this->openShift->id,
            'from_user_id' => $this->cashier->id,
            'to_user_id' => $this->cashier2->id,
            'status' => 'pending',
        ]);

        // 3. Supervisor melakukan Force Close Shift
        Livewire::actingAs($this->supervisor)
            ->test(ShiftManager::class)
            ->set('forceClosingShiftId', $this->openShift->id)
            ->set('forceCloseReason', 'Kasir darurat pulang sakit')
            ->set('forceCloseSupervisorPin', '112233')
            ->call('forceCloseShift')
            ->assertHasNoErrors();

        $freshShift = $this->openShift->fresh();
        $this->assertSame('closed', $freshShift->status);
        $this->assertStringContainsString('Kasir darurat pulang sakit', $freshShift->notes);
    }

    /**
     * Test 3: Livewire CreateOrder - Park Cart, Resume Revalidation, Manual Discount Override & Split Payment.
     */
    public function test_create_order_park_cart_discount_and_split_tender(): void
    {
        Livewire::actingAs($this->cashier)
            ->test(CreateOrder::class)
            // 1. Tambah produk ke keranjang
            ->call('addToCart', $this->retailProduct->id)
            ->assertCount('cart', 1)
            // 2. Park Cart
            ->set('holdLabel', 'Meja 5 - Tamu VIP')
            ->call('parkCurrentCart')
            ->assertCount('cart', 0)
            ->assertCount('parkedCarts', 1)
            // 3. Resume Parked Cart
            ->call('resumeCart', 0)
            ->assertCount('cart', 1)
            // 4. Set Item Discount
            ->call('setItemDiscount', $this->retailProduct->id, 5000)
            ->assertSet('itemDiscounts.'.$this->retailProduct->id, 5000)
            // 5. Manual Discount Override dengan PIN Supervisor
            ->set('manualDiscountAmountInput', '5000')
            ->set('manualDiscountReason', 'Diskon Loyalitas Pelanggan')
            ->set('supervisorPinInput', '112233')
            ->call('applyManualDiscount')
            ->assertSet('manualDiscountAmount', 5000)
            // 6. Split Tender Payment Leg
            ->set('paymentMethod', 'cash')
            ->set('paymentTendered', '20000')
            ->call('addPaymentLeg')
            ->assertCount('paymentLegs', 1);
    }

    /**
     * Test 4: Livewire OrderHistory - Void Safeguard, Return & Refund with Inventory Disposition.
     */
    public function test_order_history_return_disposition_and_void_pending_safeguard(): void
    {
        $order = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->cashier->id,
            'created_by_device_id' => $this->device->id,
            'shift_id' => $this->openShift->id,
            'order_code' => 'ORD-RETURN-TEST',
            'order_status' => OrderStatus::Paid,
            'payment_method' => PaymentMethod::Cash,
            'order_date' => now()->toDateString(),
            'subtotal_amount' => 85000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'service_charge_amount' => 0,
            'order_amount' => 85000,
            'cash_received' => 100000,
            'order_change' => 15000,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $itemRetail = OrderItem::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $order->id,
            'product_id' => $this->retailProduct->id,
            'qty' => 1,
            'order_price' => 50000,
            'order_subtotal' => 50000,
        ]);

        $itemRecipe = OrderItem::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $order->id,
            'product_id' => $this->recipeProduct->id,
            'qty' => 1,
            'order_price' => 35000,
            'order_subtotal' => 35000,
        ]);

        $payment = Payment::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $order->id,
            'shift_id' => $this->openShift->id,
            'payment_method' => PaymentMethod::Cash,
            'amount' => 85000,
            'status' => PaymentStatusEnum::Captured,
            'captured_at' => now(),
            'processed_by' => $this->cashier->id,
            'idempotency_key' => 'pay-ret-test',
        ]);

        // Buat PaymentAttempt pending untuk menguji safeguard penolakan void
        $pendingAttempt = PaymentAttempt::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $order->id,
            'payment_id' => $payment->id,
            'provider' => 'midtrans',
            'payment_method' => 'midtrans_qris',
            'amount' => 85000,
            'status' => PaymentAttemptStatus::PendingProvider,
            'idempotency_key' => 'att-pending-01',
        ]);

        // 1. Pengujian Safeguard Void ditolak jika ada pending attempt
        Livewire::actingAs($this->supervisor)
            ->test(OrderHistory::class)
            ->set('voidingOrderId', $order->id)
            ->set('voidReason', 'Pembatalan transaksi pelanggan')
            ->set('supervisorPin', '112233')
            ->call('voidOrder')
            ->assertHasErrors(['voidReason']);

        // Hapus attempt pending agar pengujian retur & refund berjalan normal
        $pendingAttempt->delete();

        // 2. Pengujian Retur & Refund via Livewire OrderHistory
        Livewire::actingAs($this->supervisor)
            ->test(OrderHistory::class)
            ->call('openReturnModal', $order->id)
            ->assertSet('showReturnModal', true)
            // Pilih item retail diretur dengan disposisi restock
            ->set("returnItems.{$itemRetail->id}.selected", true)
            ->set("returnItems.{$itemRetail->id}.qty", 1)
            ->set("returnItems.{$itemRetail->id}.disposition", 'restock')
            // Item resep tidak boleh direstock (harus write_off_waste / quarantine)
            ->set("returnItems.{$itemRecipe->id}.selected", true)
            ->set("returnItems.{$itemRecipe->id}.qty", 1)
            ->set("returnItems.{$itemRecipe->id}.disposition", 'write_off_waste')
            ->set("returnItems.{$itemRecipe->id}.disposition_reason", 'Susu tumpah/rusak')
            // Alokasi refund pembayaran
            ->set("refundAllocations.{$payment->id}.amount", 85000)
            ->set('returnReason', 'Pelanggan membatalkan pesanan setelah bayar')
            ->set('returnSupervisorPin', '112233')
            ->call('processReturn')
            ->assertSet('showReturnModal', false);

        $this->assertDatabaseHas('order_returns', [
            'order_id' => $order->id,
            'return_type' => OrderReturn::TYPE_FULL,
        ]);

        $this->assertDatabaseHas('payment_refunds', [
            'order_id' => $order->id,
            'amount' => 85000,
            'financial_status' => PaymentRefund::FINANCIAL_SETTLED,
        ]);
    }

    /**
     * Test 5: Livewire DiscountManager - Usage Limit Quota & Stackable Flag.
     */
    public function test_discount_manager_quota_and_stackable_crud(): void
    {
        Livewire::actingAs($this->manager)
            ->test(DiscountManager::class)
            ->set('name', 'Voucher Promo Ramadhan')
            ->set('code', 'RAMADHAN50')
            ->set('type', 'percentage')
            ->set('value', 20)
            ->set('max_discount_amount', 10000)
            ->set('min_purchase_amount', 30000)
            ->set('usage_limit', 50)
            ->set('is_stackable', true)
            ->call('store')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('discounts', [
            'tenant_id' => $this->tenant->id,
            'code' => 'RAMADHAN50',
            'usage_limit' => 50,
            'is_stackable' => true,
        ]);
    }

    /**
     * Test 6: ReportController - End-of-Day (EOD / Z-Report) Closing Action.
     */
    public function test_eod_z_report_closing_action_with_manager_pin(): void
    {
        // Tutup shift aktif terlebih dahulu karena EOD mewajibkan semua shift closed
        $this->openShift->update([
            'status' => 'closed',
            'closed_at' => now(),
            'expected_cash' => 200000,
            'closing_balance' => 200000,
            'cash_difference' => 0,
        ]);

        $this->actingAs($this->manager);

        $res = $this->post(route('reports.eod-close'), [
            'business_date' => now()->toDateString(),
            'pin' => '654321', // PIN Manager
            'notes' => 'Penutupan buku akhir hari lengkap & seimbang',
        ]);

        $res->assertSessionHas('success');

        $this->assertDatabaseHas('eod_settlements', [
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'business_date' => now()->toDateString(),
            'status' => EodSettlement::STATUS_CLOSED,
        ]);
    }
}
