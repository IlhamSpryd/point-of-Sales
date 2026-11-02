<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Models\Category;
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
use App\Modules\Inventory\Services\InventoryLedgerService;
use App\Modules\Sales\Exceptions\ShiftClosedException;
use App\Modules\Sales\Services\OrderReturnService;
use App\Modules\Sales\Services\ShiftManagementService;
use App\Modules\Sales\Services\SplitPaymentService;
use App\Modules\Sales\Services\TransactionService;
use App\Services\Context\TenantContext;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class Wave4PosTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;
    private Store $store;
    private User $cashier;
    private User $supervisor;
    private Device $device;
    private Category $category;
    private ShiftManagementService $shiftManagementService;
    private SplitPaymentService $splitPaymentService;
    private OrderReturnService $orderReturnService;
    private TransactionService $transactionService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = Tenant::create([
            'name' => 'Wave 4 POS Tenant',
            'slug' => 'wave4-pos-' . uniqid(),
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'POS Central Store',
            'code' => 'POS-01',
            'is_active' => true,
        ]);

        app(TenantContext::class)->setStoreId($this->store->id);

        $roleCashier = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Kasir',
            'permissions' => ['pos.order', 'pos.shift'],
        ]);

        $roleSupervisor = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Supervisor',
            'permissions' => ['*'],
        ]);

        $this->cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $roleCashier->id,
            'password' => Hash::make('password123'),
        ]);

        $this->supervisor = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $roleSupervisor->id,
            'password' => Hash::make('password123'),
        ]);

        DB::table('user_stores')->insert([
            ['tenant_id' => $this->tenant->id, 'user_id' => $this->cashier->id, 'store_id' => $this->store->id],
            ['tenant_id' => $this->tenant->id, 'user_id' => $this->supervisor->id, 'store_id' => $this->store->id],
        ]);

        // Berikan PIN Kasir & PIN Supervisor via CashierPinService
        $pinService = app(\App\Services\CashierPinService::class);
        $pinService->setPin($this->cashier, '123456');
        $pinService->setPin($this->supervisor, '654321');

        $this->device = Device::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'device_name' => 'Register POS 01',
            'device_code' => 'REG-01',
            'device_type' => 'pos_terminal',
            'device_secret' => 'secret-reg-01',
            'status' => 'active',
            'is_active' => true,
            'registered_at' => now(),
        ]);

        $this->category = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Fast Food',
        ]);

        $this->shiftManagementService = app(ShiftManagementService::class);
        $this->splitPaymentService = app(SplitPaymentService::class);
        $this->orderReturnService = app(OrderReturnService::class);
        $this->transactionService = app(TransactionService::class);
    }

    /**
     * T-POS-01: Kasir tidak dapat bertransaksi di luar sesi shift aktif (ShiftClosedException).
     */
    public function test_t_pos_01_cashier_cannot_transact_outside_active_shift_session(): void
    {
        // Pastikan tidak ada shift terbuka untuk kasir ini
        $activeShift = $this->shiftManagementService->getActiveShift(
            $this->tenant->id,
            $this->store->id,
            $this->cashier->id
        );
        $this->assertNull($activeShift);

        // Uji 1: assertActiveShift melempar ShiftClosedException
        $this->expectException(ShiftClosedException::class);
        $this->shiftManagementService->assertActiveShift(
            $this->tenant->id,
            $this->store->id,
            $this->cashier->id
        );
    }

    /**
     * T-POS-01 (Part 2): TransactionService::checkout menolak transaksi saat shift kasir tidak aktif.
     */
    public function test_t_pos_01_transaction_service_checkout_rejects_closed_shift(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'Burger Cheese',
            'product_price' => 35000,
            'stock' => 50,
            'is_active' => true,
        ]);

        // Buat shift yang sudah ditutup
        $closedShift = Shift::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->cashier->id,
            'active_cashier_id' => $this->cashier->id,
            'status' => 'closed',
            'opening_balance' => 100000,
            'closing_balance' => 100000,
            'opened_at' => now()->subHours(8),
            'closed_at' => now()->subHour(),
        ]);

        $payload = [
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->cashier->id,
            'shift_id' => $closedShift->id,
            'order_type' => 'dine_in',
            'items' => [
                [
                    'product_id' => $product->id,
                    'qty' => 1,
                    'price' => 35000,
                ],
            ],
            'payment_method' => 'cash',
            'cash_received' => 50000,
        ];

        $this->expectException(ShiftClosedException::class);
        $this->transactionService->createTransaction($payload, (int) $this->cashier->id);
    }

    /**
     * T-POS-02: Upaya membuka shift kedua oleh user yang sama pada cabang yang sama memicu penolakan unik basis data.
     */
    public function test_t_pos_02_attempting_second_open_shift_for_same_user_and_store_triggers_unique_constraint(): void
    {
        // Buka shift pertama
        $shift1 = $this->shiftManagementService->openShift(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            userId: $this->cashier->id,
            openingCash: 150000,
            deviceId: $this->device->id,
            cashierPin: '123456'
        );

        $this->assertNotNull($shift1->id);
        $this->assertEquals('open', $shift1->status);

        // Upaya membuka shift kedua melalui ShiftManagementService dicegah di domain
        try {
            $this->shiftManagementService->openShift(
                tenantId: $this->tenant->id,
                storeId: $this->store->id,
                userId: $this->cashier->id,
                openingCash: 200000,
                deviceId: $this->device->id,
                cashierPin: '123456'
            );
            $this->fail('Upaya membuka shift kedua seharusnya gagal.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('sudah memiliki shift aktif', $e->getMessage());
        }

        // Upaya mem-bypass domain dan menulis langsung ke DB wajib memicu QueryException unique constraint:
        // shifts_one_open_per_user_unique pada (tenant_id, store_id, open_shift_lock_key)
        $this->expectException(QueryException::class);
        Shift::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->cashier->id,
            'active_cashier_id' => $this->cashier->id,
            'device_id' => $this->device->id,
            'status' => 'open',
            'opening_balance' => 50000,
            'opened_at' => now(),
        ]);
    }

    /**
     * T-POS-03: Transaksi split-tender terbukti seimbang:
     * total tender pembayaran dikurangi kembalian sama persis dengan orders.total_amount / order_amount.
     */
    public function test_t_pos_03_split_tender_payments_balance_exactly_equals_order_total(): void
    {
        $shift = $this->shiftManagementService->openShift(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            userId: $this->cashier->id,
            openingCash: 100000,
            deviceId: $this->device->id,
            cashierPin: '123456'
        );

        $totalOrderAmount = 100000;

        $order = Order::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->cashier->id,
            'shift_id' => $shift->id,
            'created_by_device_id' => $this->device->id,
            'order_code' => 'ORD-SPLIT-' . uniqid(),
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            'order_date' => now()->toDateString(),
            'subtotal_amount' => $totalOrderAmount,
            'order_amount' => $totalOrderAmount,
            'order_status' => 'pending',
        ]);

        // Split-tender:
        // Leg 1: Cash Rp 40.000 (uang fisik Rp 50.000, kembalian Rp 10.000)
        // Leg 2: QRIS Rp 30.000
        // Leg 3: Debit Card Rp 30.000
        // Total Tender: 50.000 + 30.000 + 30.000 = 110.000
        // Total Kembalian: 10.000
        // Net Balance: 110.000 - 10.000 = 100.000 (SAMA PERSIS DENGAN ORDER AMOUNT)
        $legs = [
            [
                'payment_method' => 'cash',
                'amount' => 40000,
                'amount_tendered' => 50000,
            ],
            [
                'payment_method' => 'qris',
                'amount' => 30000,
                'reference_number' => 'QRIS-REF-99881',
            ],
            [
                'payment_method' => 'card',
                'amount' => 30000,
                'reference_number' => 'CARD-AUTH-77665',
            ],
        ];

        $result = $this->splitPaymentService->processSplitPayment(
            order: $order,
            legs: $legs,
            userId: $this->cashier->id,
            shiftId: $shift->id,
            deviceId: $this->device->id
        );

        $orderStatusValue = $result['order']->order_status instanceof \App\Enums\OrderStatus ? $result['order']->order_status->value : (string) $result['order']->order_status;
        $this->assertEquals('paid', $orderStatusValue);
        $this->assertEquals(110000, $result['total_tendered']);
        $this->assertEquals(10000, $result['total_change']);
        $this->assertEquals(100000, $result['net_paid']);
        $this->assertEquals($totalOrderAmount, $result['net_paid']);

        // Pastikan jumlah payments di DB tepat 3 record
        $this->assertCount(3, $result['payments']);
        $sumCapturedInDb = Payment::where('order_id', $order->id)->sum('amount');
        $this->assertEquals($totalOrderAmount, $sumCapturedInDb);
    }

    /**
     * Blind Cash Count penutupan shift dan validasi toleransi selisih kas fisik.
     */
    public function test_blind_cash_count_shift_closing_and_variance_tolerance(): void
    {
        $shift = $this->shiftManagementService->openShift(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            userId: $this->cashier->id,
            openingCash: 200000,
            deviceId: $this->device->id,
            cashierPin: '123456'
        );

        // Catat cash in & cash out
        $this->shiftManagementService->recordCashMovement(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            shiftId: $shift->id,
            userId: $this->cashier->id,
            type: 'cash_in',
            amount: 50000,
            reason: 'Tukar uang receh'
        );

        $this->shiftManagementService->recordCashMovement(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            shiftId: $shift->id,
            userId: $this->cashier->id,
            type: 'cash_out',
            amount: 20000,
            reason: 'Beli air galon'
        );

        // Expected cash = 200.000 + 50.000 - 20.000 = 230.000
        $summary = $this->shiftManagementService->getCashSummary($shift);
        $this->assertEquals(230000, $summary['expected_cash']);

        // Kasir hitung fisik 235.000 (Selisih +5.000, dalam batas toleransi 10.000)
        $closeResult = $this->shiftManagementService->closeShiftWithBlindCount(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            shiftId: $shift->id,
            userId: $this->cashier->id,
            countedCash: 235000,
            notes: 'Penutupan shift aman',
            tolerance: 10000.00
        );

        $this->assertTrue($closeResult['is_within_tolerance']);
        $this->assertEquals('closed', $closeResult['shift']->status);
        $this->assertEquals(235000, $closeResult['shift']->closing_balance);
        $this->assertEquals(5000, $closeResult['shift']->cash_difference);
    }
}
