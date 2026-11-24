<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Core\Context\TenantContext;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatusEnum;
use App\Modules\Catalog\Models\Product;
use App\Modules\Finance\Enums\AccountingPeriodStatusEnum;
use App\Modules\Finance\Enums\JournalStatusEnum;
use App\Modules\Finance\Models\AccountingPeriod;
use App\Modules\Finance\Models\FinancialEventOutbox;
use App\Modules\Finance\Services\FinancialOutboxDispatcherService;
use App\Modules\Finance\Services\PostingEngineService;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Organization\Models\Device;
use App\Modules\Organization\Models\Role;
use App\Modules\Organization\Models\User;
use App\Modules\Sales\Application\Handlers\VoidAndRestoreOrderHandler;
use App\Modules\Sales\Models\CashDrawerMovement;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderItem;
use App\Modules\Sales\Models\Payment;
use App\Modules\Sales\Models\Shift;
use App\Modules\Sales\Services\ReturnRefundService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DefaultPostingRulesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Wave6FinancialIntegrityAndReversalTest extends TestCase
{
    use RefreshDatabase;

    protected $tenant;

    protected $store;

    protected User $cashier;

    protected User $supervisor;

    protected Shift $shift;

    protected Device $device;

    protected AccountingPeriod $period;

    protected ReturnRefundService $returnService;

    protected PostingEngineService $postingEngine;

    protected FinancialOutboxDispatcherService $outboxDispatcher;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = $this->defaultTenant;
        $this->store = $this->defaultStore;

        app(TenantContext::class)->setTenantId($this->tenant->id);

        app(ChartOfAccountsSeeder::class)->seedForTenant($this->tenant->id);
        app(DefaultPostingRulesSeeder::class)->seedForTenant($this->tenant->id);

        $this->returnService = app(ReturnRefundService::class);
        $this->postingEngine = app(PostingEngineService::class);
        $this->outboxDispatcher = app(FinancialOutboxDispatcherService::class);

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

        $managerRole = Role::firstOrCreate(
            ['name' => 'Manager'],
            ['tenant_id' => $this->tenant->id, 'display_name' => 'Store Manager']
        );

        $this->supervisor = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $managerRole->id,
            'pin_hash' => '123456',
        ]);

        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->shift = Shift::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->cashier->id,
            'active_cashier_id' => $this->cashier->id,
            'starting_balance' => 500000,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $this->device = Device::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'device_name' => 'POS Register 01',
            'device_code' => 'DEV-01',
            'device_type' => 'pos',
            'status' => 'active',
        ]);
    }

    /**
     * SIMULASI A:
     * Rp 100.000, 3 items, Discount 15%, SC 5%, Tax 10%, split Cash + QRIS + Store Credit
     * Persamaan Akuntansi: SUM(Debit) === SUM(Credit) tepat hingga Rp 0,00.
     * Store Credit mendebit liability (STORE_CREDIT_LIABILITY).
     * Rounding gain positif mencatat CREDIT Pendapatan Pembulatan.
     */
    public function test_simulation_a_multi_tender_service_charge_rounding_and_store_credit_gl_balance(): void
    {
        $product1 = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_price' => 50000,
            'standard_cost' => 25000,
            'stock' => 50,
        ]);
        $product2 = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_price' => 30000,
            'standard_cost' => 15000,
            'stock' => 50,
        ]);
        $product3 = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_price' => 20000,
            'standard_cost' => 10000,
            'stock' => 50,
        ]);

        // Subtotal = 50.000 + 30.000 + 20.000 = 100.000
        // Discount 15% = 15.000 -> DPP = 85.000
        // SC 5% dari DPP = 4.250
        // Tax 10% dari (DPP + SC = 89.250) = 8.925
        // Expected Total = 85.000 + 4.250 + 8.925 = 98.175
        // Pembulatan kas +25 -> Grand Total = 98.200 (rounding_gain_amount = 25.00)
        $order = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->cashier->id,
            'shift_id' => $this->shift->id,
            'created_by_device_id' => $this->device->id,
            'order_code' => 'ORD-W6-SIM-A',
            'order_date' => now()->toDateString(),
            'idempotency_key' => 'IDEM-W6-SIM-A',
            'subtotal_amount' => '100000.00',
            'discount_amount' => '15000.00',
            'dpp_amount' => '85000.00',
            'service_charge_amount' => '4250.00',
            'tax_amount' => '8925.00',
            'rounding_adjustment' => '25.00',
            'order_amount' => '98200.00',
            'cash_received' => '98200.00',
            'order_change' => '0.00',
            'order_status' => OrderStatus::Paid,
            'payment_method' => PaymentMethod::Cash,
            'order_type' => 'dine_in',
        ]);

        OrderItem::forceCreate([
            'order_id' => $order->id,
            'product_id' => $product1->id,
            'qty' => 1,
            'order_price' => '50000.00',
            'order_subtotal' => '50000.00',
            'net_line_total' => '50000.00',
        ]);
        OrderItem::forceCreate([
            'order_id' => $order->id,
            'product_id' => $product2->id,
            'qty' => 1,
            'order_price' => '30000.00',
            'order_subtotal' => '30000.00',
            'net_line_total' => '30000.00',
        ]);
        OrderItem::forceCreate([
            'order_id' => $order->id,
            'product_id' => $product3->id,
            'qty' => 1,
            'order_price' => '20000.00',
            'order_subtotal' => '20000.00',
            'net_line_total' => '20000.00',
        ]);

        // Multi-tender split payment:
        // Cash: 48.200, QRIS (PG): 30.000, Store Credit: 20.000 -> Total = 98.200
        Payment::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $order->id,
            'shift_id' => $this->shift->id,
            'payment_method' => 'cash',
            'amount' => '48200.00',
            'amount_tendered' => '50000.00',
            'change_amount' => '1800.00',
            'status' => PaymentStatusEnum::Captured,
            'processed_by' => $this->cashier->id,
            'idempotency_key' => 'PAY-SIMA-CASH',
        ]);

        Payment::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $order->id,
            'shift_id' => $this->shift->id,
            'payment_method' => 'qris',
            'amount' => '30000.00',
            'amount_tendered' => '30000.00',
            'change_amount' => '0.00',
            'status' => PaymentStatusEnum::Captured,
            'processed_by' => $this->cashier->id,
            'idempotency_key' => 'PAY-SIMA-QRIS',
        ]);

        Payment::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $order->id,
            'shift_id' => $this->shift->id,
            'payment_method' => 'store_credit',
            'amount' => '20000.00',
            'amount_tendered' => '20000.00',
            'change_amount' => '0.00',
            'status' => PaymentStatusEnum::Captured,
            'processed_by' => $this->cashier->id,
            'idempotency_key' => 'PAY-SIMA-SC',
        ]);

        // Dispatch outbox event pos.order.paid
        $outbox = $this->outboxDispatcher->dispatchPosOrderPaid($order);
        $this->assertNotNull($outbox);
        $this->assertEquals('pos.order.paid', $outbox->event_type);

        $payload = $outbox->payload;
        $this->assertEquals('48200.00', $payload['cash_amount']);
        $this->assertEquals('30000.00', $payload['pg_clearing_amount']);
        $this->assertEquals('20000.00', $payload['store_credit_amount']);
        $this->assertEquals('4250.00', $payload['service_charge_amount']);
        $this->assertEquals('25.00', $payload['rounding_gain_amount']);
        $this->assertEquals('0.00', $payload['rounding_loss_amount']);

        // Posting jurnal via PostingEngineService
        $journal = $this->postingEngine->processOutboxEvent($outbox);
        $this->assertNotNull($journal);
        $this->assertEquals(JournalStatusEnum::POSTED, $journal->status);

        // Assert Keseimbangan Finansial: Total Debit == Total Credit
        $this->assertEquals($journal->total_debit, $journal->total_credit);
        // Total Debit: 48.200 (Cash) + 30.000 (PG) + 20.000 (SC) + 15.000 (Disc) = 113.200
        // Total Credit: 100.000 (Sales) + 8.925 (Tax) + 4.250 (SC) + 25 (Round Gain) = 113.200
        $this->assertEquals('113200.00', $journal->total_debit);

        $lines = $journal->lines;

        // Store credit debits liability
        $scLine = $lines->first(fn ($l) => $l->account->system_account_key === 'STORE_CREDIT_LIABILITY');
        $this->assertNotNull($scLine);
        $this->assertEquals('20000.00', $scLine->debit_amount);

        // Service charge credits revenue
        $svcLine = $lines->first(fn ($l) => $l->account->system_account_key === 'SERVICE_CHARGE_REVENUE');
        $this->assertNotNull($svcLine);
        $this->assertEquals('4250.00', $svcLine->credit_amount);

        // Rounding gain credits rounding income
        $rndLine = $lines->first(fn ($l) => $l->account->system_account_key === 'ROUNDING_GAIN_INCOME');
        $this->assertNotNull($rndLine);
        $this->assertEquals('25.00', $rndLine->credit_amount);
    }

    /**
     * SIMULASI B:
     * Partial return 1 of 2 BOM recipe items with voucher discount:
     * - restockInventory = false tidak melempar DomainException, mencatat waste/spoilage.
     * - Proportional refund: refund DPP, reversed discount, dan jurnal GL seimbang.
     * - Void order cash di shift baru mencatat CashDrawerMovement (cash_out) pada shift aktif,
     *   sementara shift lama yang sudah ditutup tidak dimutasi.
     */
    public function test_simulation_b_fnb_recipe_waste_return_and_void_shift_cash_reversal(): void
    {
        // 1. Buat Produk BOM Recipe (F&B)
        $ingredient = Ingredient::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'name' => 'Biji Kopi Arabika',
            'sku' => 'ING-COFFEE-01',
            'cost_per_unit' => '5000.00',
            'unit' => 'gram',
            'stock' => 1000,
        ]);

        $recipeProduct = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_name' => 'Signature Espresso Latte',
            'product_price' => 50000,
            'standard_cost' => 15000,
            'stock' => 0,
        ]);

        // Hubungkan ingredient
        $recipeProduct->ingredients()->attach($ingredient->id, [
            'tenant_id' => $this->tenant->id,
            'quantity_required' => 20,
        ]);

        // 2. Buat Order Paid dengan 2 pcs Latte @ 50.000 = 100.000, diskon voucher 20.000, cash 80.000
        $order = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->cashier->id,
            'shift_id' => $this->shift->id,
            'created_by_device_id' => $this->device->id,
            'order_code' => 'ORD-W6-SIM-B',
            'order_date' => now()->toDateString(),
            'idempotency_key' => 'IDEM-W6-SIM-B',
            'subtotal_amount' => '100000.00',
            'discount_amount' => '20000.00',
            'dpp_amount' => '80000.00',
            'service_charge_amount' => '0.00',
            'tax_amount' => '0.00',
            'rounding_adjustment' => '0.00',
            'order_amount' => '80000.00',
            'cash_received' => '80000.00',
            'order_change' => '0.00',
            'order_status' => OrderStatus::Paid,
            'payment_method' => PaymentMethod::Cash,
            'order_type' => 'dine_in',
        ]);

        $orderItem = OrderItem::forceCreate([
            'order_id' => $order->id,
            'product_id' => $recipeProduct->id,
            'qty' => 2,
            'order_price' => '50000.00',
            'order_subtotal' => '100000.00',
            'item_discount_amount' => '20000.00',
            'net_line_total' => '80000.00',
        ]);

        $payment = Payment::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $order->id,
            'shift_id' => $this->shift->id,
            'payment_method' => 'cash',
            'amount' => '80000.00',
            'amount_tendered' => '80000.00',
            'change_amount' => '0.00',
            'status' => PaymentStatusEnum::Captured,
            'processed_by' => $this->cashier->id,
            'idempotency_key' => 'PAY-SIMB-CASH',
        ]);

        // 3. Retur Parsial 1 pcs dari 2 pcs produk BOM dengan restockInventory: false
        // Rasio: 50.000 / 100.000 = 50%.
        // Diskon proporsional yang dibalikkan = 10.000.
        // Refund tunai = 50.000 - 10.000 = 40.000.
        $return = $this->returnService->createReturnAndRefund(
            order: $order,
            returnItemsWithDisposition: [
                [
                    'order_item_id' => $orderItem->id,
                    'quantity' => 1,
                    'disposition' => 'write_off_waste',
                    'disposition_reason' => 'Minuman basi/rusak tidak dapat di-restock',
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
            supervisorPin: '123456',
            reason: 'Retur parsial 1 pcs latte basi',
            idempotencyKey: 'RET-SIMB-PARTIAL',
            restockInventory: false
        );

        $this->assertNotNull($return);
        $this->assertEquals('50000.00', $return->subtotal_returned);
        $this->assertEquals('10000.00', $return->discount_reversed);
        $this->assertEquals('40000.00', $return->total_refundable_amount);

        // Proses outbox sales.return.refunded dan verifikasi keseimbangan GL
        $retOutbox = FinancialEventOutbox::where('tenant_id', $this->tenant->id)
            ->where('event_type', 'sales.return.refunded')
            ->where('source_id', $return->id)
            ->firstOrFail();

        $retJournal = $this->postingEngine->processOutboxEvent($retOutbox);
        $this->assertNotNull($retJournal);
        $this->assertEquals(JournalStatusEnum::POSTED, $retJournal->status);
        $this->assertEquals($retJournal->total_debit, $retJournal->total_credit);

        // 4. Pengujian Void Order pada Shift Kasir Baru
        // Tutup shift lama (Shift 1)
        $this->shift->status = 'closed';
        $this->shift->closed_at = now();
        $this->shift->save();

        // Buka shift baru (Shift 2)
        $newShift = Shift::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->cashier->id,
            'active_cashier_id' => $this->cashier->id,
            'starting_balance' => 300000,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        // Buat Order Paid terpisah untuk di-void
        $orderToVoid = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->cashier->id,
            'shift_id' => $this->shift->id, // Shift 1 (lama/tutup)
            'created_by_device_id' => $this->device->id,
            'order_code' => 'ORD-W6-SIM-B-VOID',
            'order_date' => now()->toDateString(),
            'idempotency_key' => 'IDEM-W6-SIM-B-VOID',
            'subtotal_amount' => '50000.00',
            'discount_amount' => '0.00',
            'dpp_amount' => '50000.00',
            'service_charge_amount' => '0.00',
            'tax_amount' => '0.00',
            'rounding_adjustment' => '0.00',
            'order_amount' => '50000.00',
            'cash_received' => '50000.00',
            'order_change' => '0.00',
            'order_status' => OrderStatus::Paid,
            'payment_method' => PaymentMethod::Cash,
            'order_type' => 'dine_in',
        ]);

        OrderItem::forceCreate([
            'order_id' => $orderToVoid->id,
            'product_id' => $recipeProduct->id,
            'qty' => 1,
            'order_price' => '50000.00',
            'order_subtotal' => '50000.00',
            'net_line_total' => '50000.00',
        ]);

        Payment::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $orderToVoid->id,
            'shift_id' => $this->shift->id,
            'payment_method' => 'cash',
            'amount' => '50000.00',
            'amount_tendered' => '50000.00',
            'change_amount' => '0.00',
            'status' => PaymentStatusEnum::Captured,
            'processed_by' => $this->cashier->id,
            'idempotency_key' => 'PAY-SIMB-VOID-CASH',
        ]);

        $initialCashOutShift1 = CashDrawerMovement::where('shift_id', $this->shift->id)->where('type', 'cash_out')->count();

        // Jalankan Void Order via VoidAndRestoreOrderHandler
        $voidHandler = app(VoidAndRestoreOrderHandler::class);
        $voidHandler->voidOrder(
            order: $orderToVoid,
            reason: 'Pembatalan transaksi oleh manajer',
            userId: $this->supervisor->id
        );

        $orderToVoid->refresh();
        $this->assertEquals(OrderStatus::Void, $orderToVoid->order_status);

        // Verifikasi Kasir Integritas (REV-02):
        // Shift lama (Shift 1) tidak mencatat pergerakan baru
        $movementsShift1 = CashDrawerMovement::where('shift_id', $this->shift->id)->where('type', 'cash_out')->count();
        $this->assertEquals($initialCashOutShift1, $movementsShift1, 'Shift lama yang sudah closed tidak boleh mencatat mutasi cash_out tambahan dari void');

        // Shift aktif saat ini (Shift 2) mencatat pergerakan cash_out sebesar 50.000
        $movementShift2 = CashDrawerMovement::where('shift_id', $newShift->id)->where('type', 'cash_out')->first();
        $this->assertNotNull($movementShift2, 'Shift aktif saat ini wajib mencatat cash_out dari void cash');
        $this->assertEquals(50000, (int) round((float) $movementShift2->amount));

        // Verifikasi GL Reversal (pos.order.voided)
        $voidOutbox = FinancialEventOutbox::where('tenant_id', $this->tenant->id)
            ->where('event_type', 'pos.order.voided')
            ->where('source_id', $orderToVoid->id)
            ->firstOrFail();

        $voidJournal = $this->postingEngine->processOutboxEvent($voidOutbox);
        $this->assertNotNull($voidJournal);
        $this->assertEquals(JournalStatusEnum::POSTED, $voidJournal->status);
        $this->assertEquals($voidJournal->total_debit, $voidJournal->total_credit);
        $this->assertEquals('50000.00', $voidJournal->total_debit);
    }
}
