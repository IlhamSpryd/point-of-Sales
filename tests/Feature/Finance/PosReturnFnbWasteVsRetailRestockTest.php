<?php

namespace Tests\Feature\Finance;

use App\Enums\OrderStatus;
use App\Models\AccountingPeriod;
use App\Models\Device;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Modules\Finance\Enums\AccountingPeriodStatusEnum;
use App\Modules\Finance\Enums\JournalStatusEnum;
use App\Modules\Finance\Models\FinancialEventOutbox;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Services\PostingEngineService;
use App\Modules\Sales\Services\ReturnRefundService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DefaultPostingRulesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosReturnFnbWasteVsRetailRestockTest extends TestCase
{
    use RefreshDatabase;

    protected $tenant;

    protected $store;

    protected User $supervisor;

    protected User $cashier;

    protected Shift $shift;

    protected Device $device;

    protected AccountingPeriod $period;

    protected Product $retailProduct;

    protected Product $fnbProduct;

    protected ReturnRefundService $returnService;

    protected PostingEngineService $postingEngine;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = $this->defaultTenant;
        $this->store = $this->defaultStore;

        app(ChartOfAccountsSeeder::class)->seedForTenant($this->tenant->id);
        app(DefaultPostingRulesSeeder::class)->seedForTenant($this->tenant->id);

        $this->returnService = app(ReturnRefundService::class);
        $this->postingEngine = app(PostingEngineService::class);

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

        $this->retailProduct = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_price' => 50000,
            'standard_cost' => 30000,
            'stock' => 50,
        ]);

        $this->fnbProduct = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_price' => 25000,
            'standard_cost' => 12000,
            'stock' => 50,
        ]);
    }

    public function test_retail_restock_and_fnb_waste_disposition_post_accurate_gl_journals(): void
    {
        // 1. Buat Order Paid dengan 1 retail item dan 1 F&B item
        $order = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->cashier->id,
            'shift_id' => $this->shift->id,
            'order_code' => 'ORD-RET-TEST-001',
            'idempotency_key' => 'ORD-IDEM-RET-001',
            'order_date' => now()->toDateString(),
            'subtotal_amount' => 75000,
            'tax_amount' => 0,
            'order_amount' => 75000,
            'cash_received' => 75000,
            'order_change' => 0,
            'order_status' => OrderStatus::Paid,
            'payment_method' => 'cash',
            'order_type' => 'dine_in',
        ]);

        $retailItem = OrderItem::forceCreate([
            'order_id' => $order->id,
            'product_id' => $this->retailProduct->id,
            'qty' => 1,
            'order_price' => 50000,
            'order_subtotal' => 50000,
            'net_line_total' => 50000,
        ]);

        $fnbItem = OrderItem::forceCreate([
            'order_id' => $order->id,
            'product_id' => $this->fnbProduct->id,
            'qty' => 1,
            'order_price' => 25000,
            'order_subtotal' => 25000,
            'net_line_total' => 25000,
        ]);

        $payment = Payment::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $order->id,
            'shift_id' => $this->shift->id,
            'payment_method' => 'cash',
            'amount' => 75000,
            'amount_tendered' => 75000,
            'change_amount' => 0,
            'status' => 'captured',
            'captured_at' => now(),
            'processed_by' => $this->cashier->id,
            'idempotency_key' => 'PAY-IDEM-RET-001',
        ]);

        // 2. Eksekusi Retur & Refund: Retail -> RESTOCK, F&B -> WRITE_OFF_WASTE
        $returnItems = [
            [
                'order_item_id' => $retailItem->id,
                'quantity' => 1,
                'disposition' => 'restock',
                'disposition_reason' => 'Pelanggan salah ambil ukuran, kemasan segel utuh',
            ],
            [
                'order_item_id' => $fnbItem->id,
                'quantity' => 1,
                'disposition' => 'write_off_waste',
                'disposition_reason' => 'Makanan sudah disajikan, tidak boleh di-restock',
            ],
        ];

        $refundAllocations = [
            [
                'payment_id' => $payment->id,
                'amount' => 75000,
                'method' => 'cash',
            ],
        ];

        $orderReturn = $this->returnService->createReturnAndRefund(
            order: $order,
            returnItemsWithDisposition: $returnItems,
            refundAllocations: $refundAllocations,
            shiftId: $this->shift->id,
            deviceId: $this->device->id,
            actorUserId: $this->cashier->id,
            supervisorPin: '123456',
            reason: 'Retur gabungan Retail Restock & F&B Waste',
            idempotencyKey: 'RET-TEST-IDEM-001'
        );

        $this->assertNotNull($orderReturn);

        // 3. Verifikasi record outbox sales.return.refunded
        $outbox = FinancialEventOutbox::where('tenant_id', $this->tenant->id)
            ->where('event_type', 'sales.return.refunded')
            ->where('source_id', $orderReturn->id)
            ->first();

        $this->assertNotNull($outbox, 'Outbox sales.return.refunded wajib terbentuk');
        $this->assertEquals('75000.00', $outbox->payload['total_refund_amount']);
        $this->assertEquals('30000.00', $outbox->payload['restock_cogs']); // Retail standard_cost
        $this->assertEquals('12000.00', $outbox->payload['waste_cogs']);   // F&B standard_cost

        // 4. Verifikasi Jurnal GL yang terposting
        $journal = JournalEntry::find($outbox->journal_entry_id);
        $this->assertNotNull($journal);
        $this->assertEquals(JournalStatusEnum::POSTED->value, $journal->status->value);

        // Invarian FIN-M7-01: Debit == Credit
        $this->assertEquals($journal->total_debit, $journal->total_credit);

        $lines = $journal->lines;

        // Sales Return Debit 75.000 vs Cash Credit 75.000
        $returnLine = $lines->first(fn ($l) => $l->account->system_account_key === 'SALES_RETURN');
        $this->assertNotNull($returnLine);
        $this->assertEquals('75000.00', $returnLine->debit_amount);

        $cashLine = $lines->first(fn ($l) => $l->account->system_account_key === 'CASH_MAIN');
        $this->assertNotNull($cashLine);
        $this->assertEquals('75000.00', $cashLine->credit_amount);

        // Retail Restock: Debit Persediaan 30.000, Credit HPP 30.000
        $invRestock = $lines->first(fn ($l) => $l->account->system_account_key === 'INVENTORY_RETAIL_PRODUCT');
        $this->assertNotNull($invRestock);
        $this->assertEquals('30000.00', $invRestock->debit_amount);

        $cogsRestock = $lines->first(fn ($l) => $l->account->system_account_key === 'COGS_RETAIL');
        $this->assertNotNull($cogsRestock);
        $this->assertEquals('30000.00', $cogsRestock->credit_amount);

        // F&B Waste: Debit Beban Waste 12.000, Credit HPP F&B 12.000 (tidak mengembalikan bahan baku)
        $wasteLine = $lines->first(fn ($l) => $l->account->system_account_key === 'INVENTORY_WASTE_EXPENSE');
        $this->assertNotNull($wasteLine);
        $this->assertEquals('12000.00', $wasteLine->debit_amount);

        $cogsFnb = $lines->first(fn ($l) => $l->account->system_account_key === 'COGS_FNB');
        $this->assertNotNull($cogsFnb);
        $this->assertEquals('12000.00', $cogsFnb->credit_amount);
    }
}
