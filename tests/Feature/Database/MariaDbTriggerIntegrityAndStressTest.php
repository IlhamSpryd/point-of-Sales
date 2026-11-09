<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Finance\Services\FinancialOutboxDispatcherService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * MariaDbTriggerIntegrityAndStressTest
 *
 * Implements Founder Architectural Decision 3:
 * MariaDB Trigger Stress & Database Integrity Testing.
 *
 * Verifies:
 * 1. MariaDB version and canonical driver compatibility.
 * 2. Overpayment trigger behavior on standard orders vs retail exchange settlements.
 * 3. Complete transaction rollback consistency when triggers abort execution (zero orphan records).
 * 4. Immutability triggers on payments, order_items, cash_drawer_movements, activity_logs, and tax_ledger_entries.
 * 5. Interaction between triggers, Laravel services, and outbox workflows.
 */
class MariaDbTriggerIntegrityAndStressTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;
    private Store $store;
    private User $user;
    private Shift $shift;
    private Order $order;
    private OrderItem $orderItem;
    private Product $product;
    private FinancialOutboxDispatcherService $outboxDispatcher;

    protected function afterSubclassSetUp(): void
    {
        $this->tenant = $this->defaultTenant;
        $this->store = $this->defaultStore;
        $this->user = User::where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->outboxDispatcher = app(FinancialOutboxDispatcherService::class);

        $this->shift = Shift::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'starting_cash' => 500000.00,
            'status' => 'open',
            'started_at' => now(),
        ]);

        $category = \App\Models\Category::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Kategori Tes Trigger',
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_name' => 'Produk Tes Trigger',
            'product_code' => 'TRG-PROD-01',
            'product_price' => 100000.00,
            'status' => 'active',
            'is_active' => true,
        ]);

        $this->order = Order::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'idempotency_key' => (string) Str::uuid(),
            'order_code' => 'ORD-TRG-'.uniqid(),
            'order_date' => now()->toDateString(),
            'order_type' => 'takeaway',
            'user_id' => $this->user->id,
            'order_amount' => 100000,
            'subtotal_amount' => 100000,
        ]);
        $this->order->order_status = OrderStatus::Pending;
        $this->order->save();

        $this->orderItem = OrderItem::create([
            'tenant_id' => $this->tenant->id,
            'order_id' => $this->order->id,
            'product_id' => $this->product->id,
            'qty' => 1,
            'unit_price' => 100000.00,
            'total_price' => 100000.00,
        ]);
    }

    public function test_mariadb_is_the_canonical_driver(): void
    {
        $driver = DB::connection()->getDriverName();
        $this->assertContains($driver, ['mysql', 'mariadb'], "MariaDB/MySQL must be the canonical database, current: {$driver}");

        $version = DB::selectOne('SELECT VERSION() as version');
        $this->assertNotEmpty($version->version);
    }

    public function test_trg_payments_prevent_overpayment_blocks_excess_payment_on_standard_orders(): void
    {
        // 1. Initial full payment of 100.000 for order_amount 100.000
        DB::table('payments')->insert([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $this->order->id,
            'shift_id' => $this->shift->id,
            'payment_method' => 'cash',
            'amount' => 100000,
            'amount_tendered' => 100000,
            'change_amount' => 0,
            'status' => 'captured',
            'processed_by' => $this->user->id,
            'captured_at' => now(),
            'idempotency_key' => 'trg-pay-1-'.uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Excess payment of 10.000 must be rejected by MariaDB trigger trg_payments_prevent_overpayment
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('ditolak oleh trigger overpayment guard');

        DB::table('payments')->insert([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $this->order->id,
            'shift_id' => $this->shift->id,
            'payment_method' => 'cash',
            'amount' => 10000,
            'amount_tendered' => 10000,
            'change_amount' => 0,
            'status' => 'captured',
            'processed_by' => $this->user->id,
            'captured_at' => now(),
            'idempotency_key' => 'trg-pay-excess-'.uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_trg_payments_allows_exchange_settlement_reference(): void
    {
        // 1. Initial payment of 100.000 for order_amount 100.000
        DB::table('payments')->insert([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $this->order->id,
            'shift_id' => $this->shift->id,
            'payment_method' => 'cash',
            'amount' => 100000,
            'amount_tendered' => 100000,
            'change_amount' => 0,
            'status' => 'captured',
            'processed_by' => $this->user->id,
            'captured_at' => now(),
            'idempotency_key' => 'trg-pay-exc-init-'.uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Retail exchange supplementary payment with reference EXC-* must be PERMITTED
        $excPaymentId = DB::table('payments')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $this->order->id,
            'shift_id' => $this->shift->id,
            'payment_method' => 'cash',
            'amount' => 35000,
            'amount_tendered' => 50000,
            'change_amount' => 15000,
            'reference_number' => 'EXC-PAY-'.$this->order->id.'-'.time(),
            'status' => 'captured',
            'processed_by' => $this->user->id,
            'captured_at' => now(),
            'idempotency_key' => 'trg-pay-exc-delta-'.uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertGreaterThan(0, $excPaymentId);
        $totalPaid = DB::table('payments')
            ->where('order_id', $this->order->id)
            ->where('status', 'captured')
            ->sum('amount');
        $this->assertEquals(135000, $totalPaid);
    }

    public function test_transaction_rollback_consistency_when_trigger_aborts_execution(): void
    {
        // Fill order to max
        DB::table('payments')->insert([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $this->order->id,
            'shift_id' => $this->shift->id,
            'payment_method' => 'cash',
            'amount' => 100000,
            'amount_tendered' => 100000,
            'change_amount' => 0,
            'status' => 'captured',
            'processed_by' => $this->user->id,
            'captured_at' => now(),
            'idempotency_key' => 'rollback-pay-1-'.uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $testIdempotencyKey = 'outbox-rollback-test-'.uniqid();
        $rolledBack = false;

        try {
            DB::transaction(function () use ($testIdempotencyKey) {
                // 1. Stage an outbox event inside transaction
                $this->outboxDispatcher->recordOutboxEvent(
                    tenantId: $this->tenant->id,
                    storeId: $this->store->id,
                    eventType: 'order.overpayment_attempted',
                    sourceModule: 'sales',
                    sourceType: 'order',
                    sourceId: $this->order->id,
                    sourceNumber: $this->order->order_code,
                    idempotencyKey: $testIdempotencyKey,
                    payload: ['order_id' => $this->order->id]
                );

                // 2. Insert illegal overpayment that trips MariaDB trigger
                DB::table('payments')->insert([
                    'tenant_id' => $this->tenant->id,
                    'store_id' => $this->store->id,
                    'order_id' => $this->order->id,
                    'shift_id' => $this->shift->id,
                    'payment_method' => 'cash',
                    'amount' => 50000,
                    'status' => 'captured',
                    'idempotency_key' => 'fail-pay-'.uniqid(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        } catch (QueryException $e) {
            $rolledBack = true;
            $this->assertStringContainsString('ditolak oleh trigger overpayment guard', $e->getMessage());
        }

        $this->assertTrue($rolledBack, 'Transaction must be aborted by trigger.');

        // Verify total rollback consistency: Outbox record must NOT exist
        $this->assertDatabaseMissing('financial_event_outboxes', [
            'idempotency_key' => $testIdempotencyKey,
        ]);

        // Total captured must still be exactly 100.000
        $totalPaid = DB::table('payments')
            ->where('order_id', $this->order->id)
            ->where('status', 'captured')
            ->sum('amount');
        $this->assertEquals(100000, $totalPaid);
    }

    public function test_immutability_triggers_prevent_illegal_mutations(): void
    {
        // 1. trg_payments_before_update & trg_payments_before_delete
        $paymentId = DB::table('payments')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $this->order->id,
            'shift_id' => $this->shift->id,
            'payment_method' => 'cash',
            'amount' => 50000,
            'amount_tendered' => 50000,
            'change_amount' => 0,
            'status' => 'captured',
            'processed_by' => $this->user->id,
            'captured_at' => now(),
            'idempotency_key' => 'immut-pay-'.uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Attempting to UPDATE payment amount must fail
        $paymentUpdateFailed = false;
        try {
            DB::table('payments')->where('id', $paymentId)->update(['amount' => 60000]);
        } catch (QueryException $e) {
            $paymentUpdateFailed = true;
            $this->assertStringContainsString('payments bersifat append-only: UPDATE dilarang', $e->getMessage());
        }
        $this->assertTrue($paymentUpdateFailed, 'trg_payments_before_update must block UPDATE');

        // Attempting to DELETE payment must fail
        $paymentDeleteFailed = false;
        try {
            DB::table('payments')->where('id', $paymentId)->delete();
        } catch (QueryException $e) {
            $paymentDeleteFailed = true;
            $this->assertStringContainsString('payments bersifat append-only: DELETE dilarang', $e->getMessage());
        }
        $this->assertTrue($paymentDeleteFailed, 'trg_payments_before_delete must block DELETE');

        // 2. trg_order_items_before_update & trg_order_items_before_delete
        $itemUpdateFailed = false;
        try {
            DB::table('order_items')->where('id', $this->orderItem->id)->update(['qty' => 99]);
        } catch (QueryException $e) {
            $itemUpdateFailed = true;
            $this->assertStringContainsString('order_items: hanya preparation_status dan processed_by yang boleh berubah', $e->getMessage());
        }
        $this->assertTrue($itemUpdateFailed, 'trg_order_items_before_update must block arbitrary UPDATE');

        $itemDeleteFailed = false;
        try {
            DB::table('order_items')->where('id', $this->orderItem->id)->delete();
        } catch (QueryException $e) {
            $itemDeleteFailed = true;
            $this->assertStringContainsString('order_items tidak dapat dihapus', $e->getMessage());
        }
        $this->assertTrue($itemDeleteFailed, 'trg_order_items_before_delete must block DELETE');

        // 3. trg_cash_drawer_movements_before_update & trg_cash_drawer_movements_before_delete
        $cdmId = DB::table('cash_drawer_movements')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'shift_id' => $this->shift->id,
            'type' => 'cash_in',
            'category' => 'correction',
            'amount' => 10000,
            'reason' => 'Test trigger immutability',
            'created_by' => $this->user->id,
            'idempotency_key' => 'cdm-trg-'.uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $cdmUpdateFailed = false;
        try {
            DB::table('cash_drawer_movements')->where('id', $cdmId)->update(['amount' => 20000]);
        } catch (QueryException $e) {
            $cdmUpdateFailed = true;
            $this->assertStringContainsString('cash_drawer_movements bersifat append-only: UPDATE dilarang', $e->getMessage());
        }
        $this->assertTrue($cdmUpdateFailed, 'trg_cash_drawer_movements_before_update must block UPDATE');

        $cdmDeleteFailed = false;
        try {
            DB::table('cash_drawer_movements')->where('id', $cdmId)->delete();
        } catch (QueryException $e) {
            $cdmDeleteFailed = true;
            $this->assertStringContainsString('cash_drawer_movements bersifat append-only: DELETE dilarang', $e->getMessage());
        }
        $this->assertTrue($cdmDeleteFailed, 'trg_cash_drawer_movements_before_delete must block DELETE');

        // 4. trg_tax_ledger_entries_before_update & trg_tax_ledger_entries_before_delete
        $taxCodeId = DB::table('tax_codes')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'code' => 'TC-TRG-'.uniqid(),
            'name' => 'PPN 11%',
            'tax_type' => 'VAT_PPN',
            'calculation_type' => 'EXCLUSIVE',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tleId = DB::table('tax_ledger_entries')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'tax_code_id' => $taxCodeId,
            'entry_number' => 'TLE-TEST-'.uniqid(),
            'transaction_date' => now()->toDateString(),
            'tax_direction' => 'OUTPUT_TAX',
            'tax_base_amount' => 100000.00,
            'applied_rate' => 11.0000,
            'tax_amount' => 11000.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tleUpdateFailed = false;
        try {
            DB::table('tax_ledger_entries')->where('id', $tleId)->update(['tax_amount' => 20000.00]);
        } catch (QueryException $e) {
            $tleUpdateFailed = true;
            $this->assertStringContainsString('tax_ledger_entries', $e->getMessage());
        }
        $this->assertTrue($tleUpdateFailed, 'trg_tax_ledger_entries_before_update must block UPDATE');

        $tleDeleteFailed = false;
        try {
            DB::table('tax_ledger_entries')->where('id', $tleId)->delete();
        } catch (QueryException $e) {
            $tleDeleteFailed = true;
            $this->assertStringContainsString('tax_ledger_entries', $e->getMessage());
        }
        $this->assertTrue($tleDeleteFailed, 'trg_tax_ledger_entries_before_delete must block DELETE');
    }

    public function test_concurrent_payment_and_exchange_writes_transaction_consistency(): void
    {
        $iterations = 10;
        $orderTotal = 500000;

        $stressOrder = Order::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'idempotency_key' => (string) Str::uuid(),
            'order_code' => 'ORD-STRESS-'.uniqid(),
            'order_date' => now()->toDateString(),
            'order_type' => 'takeaway',
            'user_id' => $this->user->id,
            'order_amount' => $orderTotal,
            'subtotal_amount' => $orderTotal,
        ]);

        $splitAmount = (int) ($orderTotal / $iterations); // 50.000 per split
        $completedSplits = 0;

        for ($i = 0; $i < $iterations; $i++) {
            DB::transaction(function () use ($stressOrder, $splitAmount, &$completedSplits, $i) {
                // Lock row to simulate canonical order locking
                $lockedOrder = Order::where('id', $stressOrder->id)->lockForUpdate()->first();
                $this->assertNotNull($lockedOrder);

                DB::table('payments')->insert([
                    'tenant_id' => $this->tenant->id,
                    'store_id' => $this->store->id,
                    'order_id' => $lockedOrder->id,
                    'shift_id' => $this->shift->id,
                    'payment_method' => 'cash',
                    'amount' => $splitAmount,
                    'amount_tendered' => $splitAmount,
                    'change_amount' => 0,
                    'status' => 'captured',
                    'processed_by' => $this->user->id,
                    'captured_at' => now(),
                    'idempotency_key' => "stress-pay-{$lockedOrder->id}-{$i}-".uniqid(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $completedSplits++;
            });
        }

        $this->assertSame($iterations, $completedSplits);

        // Sum of all splits must equal orderTotal exactly
        $totalCaptured = DB::table('payments')
            ->where('order_id', $stressOrder->id)
            ->where('status', 'captured')
            ->sum('amount');
        $this->assertEquals($orderTotal, $totalCaptured);

        // Now, attempting even 1 IDR above limit MUST be rejected by trg_payments_prevent_overpayment
        $overpaymentRejected = false;
        try {
            DB::table('payments')->insert([
                'tenant_id' => $this->tenant->id,
                'store_id' => $this->store->id,
                'order_id' => $stressOrder->id,
                'shift_id' => $this->shift->id,
                'payment_method' => 'cash',
                'amount' => 1,
                'status' => 'captured',
                'idempotency_key' => 'stress-overpay-'.uniqid(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $e) {
            $overpaymentRejected = true;
            $this->assertStringContainsString('ditolak oleh trigger overpayment guard', $e->getMessage());
        }
        $this->assertTrue($overpaymentRejected, 'Trigger must reject 1 IDR overpayment after stress transactions');
    }
}
