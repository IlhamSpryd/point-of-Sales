<?php

declare(strict_types=1);

namespace Tests\Feature\Retail;

use App\Enums\OrderStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Retail\Services\RetailExchangeService;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class RetailExchangeFinancialSettlementTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;
    private Store $store;
    private User $user;
    private Shift $shift;
    private Product $product;
    private ProductVariant $variantSmall;
    private ProductVariant $variantLarge;
    private Order $order;
    private OrderItem $orderItem;
    private RetailExchangeService $service;

    protected function afterSubclassSetUp(): void
    {
        $this->tenant = $this->defaultTenant;
        $this->store = $this->defaultStore;
        $this->user = User::where('tenant_id', $this->tenant->id)->firstOrFail();

        $this->shift = Shift::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'starting_cash' => 500000.00,
            'status' => 'open',
            'started_at' => now(),
        ]);

        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Fashion Retail',
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_name' => 'Kemeja Katun Signature',
            'product_code' => 'KMT-001',
            'product_price' => 200000.00,
            'product_type' => 'variant_parent',
            'has_variants' => true,
            'status' => 'active',
            'is_active' => true,
        ]);

        $this->variantSmall = ProductVariant::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $this->product->id,
            'variant_name' => 'Ukuran M',
            'sku' => 'KMT-M',
            'price_override' => 200000.00,
            'is_active' => true,
        ]);

        $this->variantLarge = ProductVariant::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $this->product->id,
            'variant_name' => 'Ukuran XXL Eksklusif',
            'sku' => 'KMT-XXL',
            'price_override' => 250000.00, // Selisih +50.000
            'is_active' => true,
        ]);

        $this->order = Order::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'idempotency_key' => (string) Str::uuid(),
            'order_code' => 'ORD-EXC-'.time(),
            'order_date' => now()->toDateString(),
            'order_type' => 'takeaway',
            'user_id' => $this->user->id,
            'order_amount' => 200000,
            'subtotal_amount' => 200000,
        ]);
        $this->order->order_status = OrderStatus::Paid;
        $this->order->save();

        // Initial payment untuk order 1
        \Illuminate\Support\Facades\DB::table('payments')->insert([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $this->order->id,
            'shift_id' => $this->shift->id,
            'payment_method' => 'cash',
            'amount' => 200000,
            'amount_tendered' => 200000,
            'change_amount' => 0,
            'status' => 'captured',
            'processed_by' => $this->user->id,
            'captured_at' => now(),
            'idempotency_key' => 'init-pay-'.uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->orderItem = OrderItem::create([
            'tenant_id' => $this->tenant->id,
            'order_id' => $this->order->id,
            'product_id' => $this->product->id,
            'product_variant_id' => $this->variantSmall->id,
            'qty' => 1,
            'unit_price' => 200000.00,
            'total_price' => 200000.00,
        ]);

        $this->service = app(RetailExchangeService::class);
    }

    public function test_exchange_variant_with_positive_difference_collects_payment_and_posts_outbox(): void
    {
        $result = $this->service->exchangeVariant(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            orderId: $this->order->id,
            oldVariantId: $this->variantSmall->id,
            newVariantId: $this->variantLarge->id,
            quantity: 1,
            userId: $this->user->id,
            reason: 'Tukar ke ukuran lebih besar XXL',
            paymentMethod: 'cash',
            amountTendered: 100000.00, // Membayar 100rb untuk selisih 50rb
            shiftId: $this->shift->id
        );

        $this->assertSame('COMPLETED', $result['status']);
        $this->assertEquals(50000.00, $result['price_difference']);
        $this->assertNotNull($result['payment_id']);

        // 1. Verifikasi transaksi payment tercatat
        $this->assertDatabaseHas('payments', [
            'id' => $result['payment_id'],
            'tenant_id' => $this->tenant->id,
            'order_id' => $this->order->id,
            'amount' => 50000,
            'amount_tendered' => 100000,
            'change_amount' => 50000,
            'status' => 'captured',
            'payment_method' => 'cash',
        ]);

        // 2. Verifikasi cash drawer movement bertambah
        $this->assertDatabaseHas('cash_drawer_movements', [
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'shift_id' => $this->shift->id,
            'type' => 'cash_in',
            'amount' => 50000,
        ]);

        // 3. Verifikasi financial event outbox terposting
        $this->assertDatabaseHas('financial_event_outboxes', [
            'tenant_id' => $this->tenant->id,
            'event_type' => 'retail.variant_exchange_revenue',
            'source_id' => $this->order->id,
        ]);

        // 4. Verifikasi audit ledger mutasi stok mencatat variant_id
        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $this->order->id,
            'variant_id' => $this->variantSmall->id,
            'type' => 'RESTORE_COMPENSATION',
            'quantity' => '1.0000',
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $this->order->id,
            'variant_id' => $this->variantLarge->id,
            'type' => 'SALE_DEDUCTION',
            'quantity' => '-1.0000',
        ]);
    }

    public function test_exchange_variant_with_negative_difference_processes_refund_and_posts_outbox(): void
    {
        // Tukar dari Large (250rb) ke Small (200rb) -> Toko kembalikan 50rb
        $order2 = Order::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'idempotency_key' => (string) Str::uuid(),
            'order_code' => 'ORD-REF-'.time(),
            'order_date' => now()->toDateString(),
            'order_type' => 'takeaway',
            'user_id' => $this->user->id,
            'order_amount' => 250000,
            'subtotal_amount' => 250000,
        ]);
        $order2->order_status = OrderStatus::Paid;
        $order2->save();

        // Initial payment untuk order 2
        \Illuminate\Support\Facades\DB::table('payments')->insert([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $order2->id,
            'shift_id' => $this->shift->id,
            'payment_method' => 'cash',
            'amount' => 250000,
            'amount_tendered' => 250000,
            'change_amount' => 0,
            'status' => 'captured',
            'processed_by' => $this->user->id,
            'captured_at' => now(),
            'idempotency_key' => 'init-pay-2-'.uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        OrderItem::create([
            'tenant_id' => $this->tenant->id,
            'order_id' => $order2->id,
            'product_id' => $this->product->id,
            'product_variant_id' => $this->variantLarge->id,
            'qty' => 1,
            'unit_price' => 250000.00,
            'total_price' => 250000.00,
        ]);

        $result = $this->service->exchangeVariant(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            orderId: $order2->id,
            oldVariantId: $this->variantLarge->id,
            newVariantId: $this->variantSmall->id,
            quantity: 1,
            userId: $this->user->id,
            reason: 'Tukar ke ukuran lebih kecil M',
            paymentMethod: 'cash',
            shiftId: $this->shift->id
        );

        $this->assertSame('COMPLETED', $result['status']);
        $this->assertEquals(-50000.00, $result['price_difference']);
        $this->assertNotNull($result['payment_id']);

        // 1. Verifikasi transaksi refund tercatat
        $this->assertDatabaseHas('payments', [
            'id' => $result['payment_id'],
            'tenant_id' => $this->tenant->id,
            'order_id' => $order2->id,
            'amount' => 50000,
            'status' => 'refunded',
        ]);

        // 2. Verifikasi cash drawer movement keluar
        $this->assertDatabaseHas('cash_drawer_movements', [
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'shift_id' => $this->shift->id,
            'type' => 'cash_out',
            'category' => 'refund',
            'amount' => 50000,
        ]);

        // 3. Verifikasi financial event outbox terposting
        $this->assertDatabaseHas('financial_event_outboxes', [
            'tenant_id' => $this->tenant->id,
            'event_type' => 'retail.variant_exchange_refund',
            'source_id' => $order2->id,
        ]);
    }

    public function test_exchange_variant_with_insufficient_tender_throws_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('kurang dari tagihan selisih');

        $this->service->exchangeVariant(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            orderId: $this->order->id,
            oldVariantId: $this->variantSmall->id,
            newVariantId: $this->variantLarge->id,
            quantity: 1,
            userId: $this->user->id,
            reason: 'Tukar ke XXL tapi uang kurang',
            paymentMethod: 'cash',
            amountTendered: 30000.00, // Butuh 50rb, hanya bayar 30rb
            shiftId: $this->shift->id
        );
    }

    public function test_standard_order_still_blocks_overpayment_via_mariadb_trigger(): void
    {
        // Pastikan trigger overpayment guard tetap aktif dan memblokir overpayment non-exchange
        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessage('Total pembayaran captured melebihi order_amount');

        \Illuminate\Support\Facades\DB::table('payments')->insert([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $this->order->id, // Order amount 200rb, sudah ada payment 200rb
            'shift_id' => $this->shift->id,
            'payment_method' => 'cash',
            'amount' => 10000, // Coba tambah 10rb tanpa prefix EXC-
            'amount_tendered' => 10000,
            'change_amount' => 0,
            'reference_number' => 'STD-PAY-'.time(),
            'status' => 'captured',
            'processed_by' => $this->user->id,
            'captured_at' => now(),
            'idempotency_key' => 'dup-test-'.uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
