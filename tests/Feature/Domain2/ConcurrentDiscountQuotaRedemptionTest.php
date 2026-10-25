<?php

declare(strict_types=1);

namespace Tests\Feature\Domain2;

use App\Models\Category;
use App\Models\Device;
use App\Models\Discount;
use App\Models\DiscountRedemption;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use App\Services\TransactionService;
use Closure;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Throwable;

/**
 * ConcurrentDiscountQuotaRedemptionTest
 *
 * Menguji kepatuhan terhadap:
 * - AC-4.1: Sepuluh request checkout paralel yang memperebutkan voucher dengan
 *           sisa kuota 1 hanya meloloskan tepat 1 order dan menolak 9 order lainnya.
 * - INV-11: ACID & Idempotensi pemotongan kuota voucher.
 * - Reversal kuota saat void order.
 * - Alokasi proporsional diskon order ke order_items (Largest Remainder Method).
 * - Aturan stacking OQ-05 (is_stackable).
 *
 * @group chaos
 */
class ConcurrentDiscountQuotaRedemptionTest extends TestCase
{
    use DatabaseTruncation;

    protected bool $optOutFromDefaultTenant = true;

    private int $tenantId;

    private int $storeId;

    private int $deviceId;

    private int $userId;

    private int $shiftId;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $context = app(TenantContext::class);
        $context->clear();

        $tenant = Tenant::create(['name' => 'Discount Tenant '.Str::random(6), 'readiness_status' => 'active']);
        $this->tenantId = (int) $tenant->id;

        $store = Store::withoutTenantScope()->create([
            'tenant_id' => $this->tenantId,
            'name' => 'Discount Store '.Str::random(6),
        ]);
        $this->storeId = (int) $store->id;

        $context->setTenantId($this->tenantId);
        $context->setStoreId($this->storeId);

        $device = Device::create([
            'tenant_id' => $this->tenantId,
            'store_id' => $this->storeId,
            'device_name' => 'POS-Terminal-1',
            'device_code' => 'DEV-'.strtoupper(Str::random(8)),
            'device_type' => 'pos',
            'is_active' => true,
        ]);
        $this->deviceId = (int) $device->id;

        $user = User::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Cashier '.Str::random(6),
            'email' => 'cashier-'.uniqid().'@test.com',
            'password' => 'password',
        ]);
        $user->stores()->attach($this->storeId, ['tenant_id' => $this->tenantId]);
        $this->userId = (int) $user->id;

        $shift = Shift::create([
            'tenant_id' => $this->tenantId,
            'store_id' => $this->storeId,
            'device_id' => $this->deviceId,
            'user_id' => $this->userId,
            'status' => 'open',
            'opening_balance' => 1000000,
            'opened_at' => now(),
        ]);
        $this->shiftId = (int) $shift->id;
    }

    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    private function createProduct(int $stock = 100, int $price = 50000): Product
    {
        $category = Category::create([
            'tenant_id' => $this->tenantId,
            'category_name' => 'Category '.Str::random(6),
        ]);

        return Product::create([
            'tenant_id' => $this->tenantId,
            'category_id' => $category->id,
            'product_name' => 'Product '.Str::random(6),
            'product_price' => $price,
            'stock' => $stock,
            'is_active' => true,
        ]);
    }

    private function makeWorker(string $database, int $tenantId, int $storeId, int $userId, array $payload, float $startAt): Closure
    {
        return static function () use ($database, $tenantId, $storeId, $userId, $payload, $startAt): array {
            $default = config('database.default');
            config(["database.connections.{$default}.database" => $database]);
            DB::purge($default);

            app(TenantContext::class)->setTenantId($tenantId);
            app(TenantContext::class)->setStoreId($storeId);

            while (microtime(true) < $startAt) {
                usleep(500);
            }

            try {
                $order = app(TransactionService::class)->createTransaction($payload, $userId);

                return ['ok' => true, 'order_id' => $order->id, 'order_code' => $order->order_code, 'message' => null];
            } catch (Throwable $e) {
                return [
                    'ok' => false,
                    'order_id' => null,
                    'order_code' => null,
                    'message' => $e instanceof ValidationException
                        ? (string) collect($e->errors())->flatten()->first()
                        : $e->getMessage(),
                ];
            }
        };
    }

    /**
     * AC-4.1: Sepuluh request checkout paralel yang memperebutkan voucher dengan
     * sisa kuota 1 hanya meloloskan tepat 1 order dan menolak 9 order lainnya.
     */
    public function test_ten_parallel_checkouts_for_single_quota_voucher_allows_only_one_order_ac_4_1(): void
    {
        $product = $this->createProduct(stock: 100, price: 50000);

        $discount = Discount::create([
            'tenant_id' => $this->tenantId,
            'store_id' => $this->storeId,
            'code' => 'LIMITED1',
            'name' => 'Limited Voucher Rp10.000',
            'type' => 'fixed',
            'value' => 10000,
            'usage_limit' => 1,
            'is_active' => true,
            'min_purchase_amount' => 10000,
        ]);

        $database = (string) config('database.connections.'.config('database.default').'.database');
        $startAt = microtime(true) + 2.5;
        $workers = [];

        for ($i = 0; $i < 10; $i++) {
            $payload = [
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 1],
                ],
                'discount_id' => $discount->id,
                'payment_method' => 'cash',
                'cash_received' => 50000,
                'shift_id' => $this->shiftId,
                'device_id' => $this->deviceId,
                'idempotency_key' => (string) Str::uuid(),
            ];
            $workers[] = $this->makeWorker($database, $this->tenantId, $this->storeId, $this->userId, $payload, $startAt);
        }

        $results = array_values(Concurrency::driver('process')->run($workers));

        $successes = collect($results)->where('ok', true);
        $failures = collect($results)->where('ok', false);

        $this->assertCount(1, $successes, 'Tepat 1 checkout yang boleh berhasil memakai voucher kuota 1');
        $this->assertCount(9, $failures, '9 checkout paralel lainnya harus ditolak karena kuota habis');

        // Pastikan pesan penolakan relevan dengan kuota habis
        foreach ($failures as $failure) {
            $this->assertStringContainsString('kuota', mb_strtolower($failure['message']));
        }

        // Verifikasi tabel ledger discount_redemptions
        $appliedRedemptions = DiscountRedemption::where('tenant_id', $this->tenantId)
            ->where('discount_id', $discount->id)
            ->where('status', DiscountRedemption::STATUS_APPLIED)
            ->get();

        $this->assertCount(1, $appliedRedemptions);
        $this->assertEquals(10000, (int) $appliedRedemptions->first()->amount_discounted);
    }

    /**
     * Menguji pengembalian kuota voucher saat pesanan di-void.
     */
    public function test_voiding_order_restores_voucher_quota_safely(): void
    {
        $product = $this->createProduct(stock: 10, price: 50000);

        $discount = Discount::create([
            'tenant_id' => $this->tenantId,
            'store_id' => $this->storeId,
            'code' => 'RECOVER1',
            'name' => 'Voucher Sekali Pakai',
            'type' => 'fixed',
            'value' => 10000,
            'usage_limit' => 1,
            'is_active' => true,
            'min_purchase_amount' => 10000,
        ]);

        $service = app(TransactionService::class);

        // Checkout 1 memakai voucher
        $order1 = $service->createTransaction([
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'discount_id' => $discount->id,
            'payment_method' => 'cash',
            'cash_received' => 50000,
            'shift_id' => $this->shiftId,
            'device_id' => $this->deviceId,
            'idempotency_key' => (string) Str::uuid(),
        ], $this->userId);

        $this->assertEquals(1, $discount->fresh()->getAppliedRedemptionsCount());

        // Checkout 2 mencoba memakai voucher yang sama -> harus ditolak
        $rejected = false;
        try {
            $service->createTransaction([
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'discount_id' => $discount->id,
                'payment_method' => 'cash',
                'cash_received' => 50000,
                'shift_id' => $this->shiftId,
                'device_id' => $this->deviceId,
                'idempotency_key' => (string) Str::uuid(),
            ], $this->userId);
        } catch (ValidationException $e) {
            $rejected = true;
        }
        $this->assertTrue($rejected, 'Checkout kedua wajib ditolak saat kuota habis');

        // Void order 1
        $service->voidOrder($order1, 'Customer cancel', $this->userId);

        // Status redemption harus berbalik menjadi voided_reversed
        $redemption = DiscountRedemption::where('order_id', $order1->id)->first();
        $this->assertNotNull($redemption);
        $this->assertEquals(DiscountRedemption::STATUS_VOIDED_REVERSED, $redemption->status);
        $this->assertNotNull($redemption->reversed_at);

        // Kuota pulih -> checkout 2 sekarang harus berhasil
        $order2 = $service->createTransaction([
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'discount_id' => $discount->id,
            'payment_method' => 'cash',
            'cash_received' => 50000,
            'shift_id' => $this->shiftId,
            'device_id' => $this->deviceId,
            'idempotency_key' => (string) Str::uuid(),
        ], $this->userId);

        $this->assertNotNull($order2);
        $this->assertEquals($discount->id, $order2->discount_id);
    }

    /**
     * Menguji alokasi proporsional diskon order ke order_items (Largest Remainder Method)
     * dan kepatuhan terhadap formula rekonsiliasi database (03_order_totals.sql).
     */
    public function test_item_level_and_order_level_discount_proportional_allocation_and_parity(): void
    {
        $product1 = $this->createProduct(stock: 10, price: 30000);
        $product2 = $this->createProduct(stock: 10, price: 20000);
        $product3 = $this->createProduct(stock: 10, price: 50000);

        // Diskon order Rp10.000 dengan flag is_stackable = true
        $orderDiscount = Discount::create([
            'tenant_id' => $this->tenantId,
            'store_id' => $this->storeId,
            'code' => 'ORDER10K',
            'name' => 'Diskon Order Rp10.000',
            'type' => 'fixed',
            'value' => 10000,
            'is_stackable' => true,
            'is_active' => true,
        ]);

        $service = app(TransactionService::class);

        // Item 1 mendapat diskon item Rp5.000 (subtotal 30.000 - 5.000 = 25.000)
        // Item 2 subtotal 20.000
        // Item 3 subtotal 50.000
        // Total effective subtotal = 25.000 + 20.000 + 50.000 = 95.000
        // Diskon order Rp10.000 dialokasikan proporsional:
        // Item 1 rasio 25/95 -> round(10.000 * 25/95) = round(2631.57) = 2632
        // Item 2 rasio 20/95 -> round(10.000 * 20/95) = round(2105.26) = 2105
        // Item 3 remainder -> 10.000 - (2632 + 2105) = 10.000 - 4737 = 5263
        $order = $service->createTransaction([
            'items' => [
                ['product_id' => $product1->id, 'quantity' => 1, 'item_discount_amount' => 5000],
                ['product_id' => $product2->id, 'quantity' => 1, 'item_discount_amount' => 0],
                ['product_id' => $product3->id, 'quantity' => 1, 'item_discount_amount' => 0],
            ],
            'discount_id' => $orderDiscount->id,
            'payment_method' => 'cash',
            'cash_received' => 150000,
            'shift_id' => $this->shiftId,
            'device_id' => $this->deviceId,
            'idempotency_key' => (string) Str::uuid(),
        ], $this->userId);

        $items = OrderItem::where('order_id', $order->id)->orderBy('id')->get();
        $this->assertCount(3, $items);

        $sumAllocated = $items->sum('allocated_order_discount_amount');
        $this->assertEquals(10000.00, (float) $sumAllocated, 'Total alokasi diskon per item wajib persis sama dengan diskon order (Rp10.000)');

        // Periksa net line total
        foreach ($items as $item) {
            $expectedNet = (float) $item->order_subtotal - (float) $item->item_discount_amount - (float) $item->allocated_order_discount_amount;
            $this->assertEquals($expectedNet, (float) $item->net_line_total);
        }

        // Verifikasi kepatuhan terhadap 03_order_totals.sql
        $this->assertEquals(
            (int) $order->order_amount,
            (int) ($order->subtotal_amount - $order->discount_amount + $order->tax_amount + ($order->service_charge_amount ?? 0))
        );
        $this->assertEquals((int) $order->subtotal_amount, (int) $items->sum('order_subtotal'));
    }

    /**
     * OQ-05 Opsi A: Diskon order dengan is_stackable = false dilarang digabung dengan diskon item.
     */
    public function test_stacking_disallowed_when_is_stackable_is_false_oq_05(): void
    {
        $product = $this->createProduct(stock: 10, price: 50000);

        $orderDiscount = Discount::create([
            'tenant_id' => $this->tenantId,
            'store_id' => $this->storeId,
            'code' => 'NOSTACK',
            'name' => 'Diskon Order Non-Stackable',
            'type' => 'fixed',
            'value' => 5000,
            'is_stackable' => false,
            'is_active' => true,
        ]);

        $this->expectException(ValidationException::class);

        app(TransactionService::class)->createTransaction([
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1, 'item_discount_amount' => 2000],
            ],
            'discount_id' => $orderDiscount->id,
            'payment_method' => 'cash',
            'cash_received' => 50000,
            'shift_id' => $this->shiftId,
            'device_id' => $this->deviceId,
            'idempotency_key' => (string) Str::uuid(),
        ], $this->userId);
    }
}
