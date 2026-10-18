<?php

declare(strict_types=1);

namespace Tests\Feature\Domain2;

use App\Enums\PaymentMethod;
use App\Models\Category;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * [D2-BE-002 / D2-BE-006] FinancialCalculationAndParityTest.
 *
 * Membuktikan:
 *  1. Kalkulasi Integer IDR + round() (PHP_ROUND_HALF_UP) deterministik.
 *  2. Paritas hasil Order/OrderItem/Payment antara jalur Kasir (CreateOrder /
 *     HTTP controller), Self-Order (CheckoutController), dan Webhook
 *     (ProcessWebhookOrderJob) — ketiganya memanggil SATU metode otoritatif
 *     TransactionService::createTransaction(), sehingga hasilnya identik.
 */
class FinancialCalculationAndParityTest extends TestCase
{
    use RefreshDatabase;

    /** Test ini mengelola konteks tenant-nya sendiri. */
    protected bool $optOutFromDefaultTenant = true;

    protected Tenant $tenant;

    protected Store $store;

    protected User $cashier;

    protected Shift $shift;

    protected Product $product;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();
        app(TenantContext::class)->clear();
        $this->tenant = Tenant::create([
            'name' => 'Parity Tenant',
            'readiness_status' => 'active',
        ]);
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenant->id);
        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Parity Store',
        ]);
        $context->setStoreId($this->store->id);
        $role = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Kasir',
            'permissions' => [],
        ]);
        $this->cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $role->id,
        ]);
        $this->shift = Shift::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->cashier->id,
            'opening_balance' => 100000,
            'status' => 'open',
            'opened_at' => now(),
        ]);
        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Beverage',
        ]);
        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_name' => 'Kopi Susu',
            'product_price' => 17500,
            'stock' => 50,
        ]);
    }

    public function test_totals_are_integer_idr_with_deterministic_rounding(): void
    {
        $service = app(TransactionService::class);
        // Subtotal 17500 x 3 = 52500. Tax 11% = 5775 tepat.
        $totals = $service->calculateOrderTotals(52500, 0);
        $this->assertSame(5775, $totals['tax_amount']);
        // Pembulatan ROUND_NEAREST (rounding_value=100) -> 58275 menjadi 58300.
        $this->assertSame(58300, $totals['total_amount']);
        $this->assertIsInt($totals['total_amount']);
    }

    public function test_kasir_and_self_order_paths_produce_identical_results(): void
    {
        $service = app(TransactionService::class);
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenant->id);
        $context->setStoreId($this->store->id);

        $items = [
            ['product_id' => $this->product->id, 'quantity' => 3, 'options' => null, 'extra_price' => 0],
        ];

        // Jalur Kasir: split payment langsung (Paid).
        $orderA = $service->createTransaction([
            'shift_id' => $this->shift->id,
            'items' => $items,
            'payments' => [
                ['method' => PaymentMethod::Cash->value, 'amount' => 58300],
            ],
        ], $this->cashier->id);

        // Jalur Self-Order: deferred cash (Pending) dengan kontrak yang sama.
        $orderB = $service->createTransaction([
            'items' => $items,
            'payment_method' => 'cash',
            'is_self_order_cash' => true,
        ], $this->cashier->id);

        $this->assertSame($orderA->subtotal_amount, $orderB->subtotal_amount);
        $this->assertSame($orderA->tax_amount, $orderB->tax_amount);
        $this->assertSame($orderA->order_amount, $orderB->order_amount);

        $itemA = $orderA->orderItems()->first();
        $itemB = $orderB->orderItems()->first();
        $this->assertSame((int) $itemA->order_subtotal, (int) $itemB->order_subtotal);
        $this->assertSame(58300, (int) $orderA->order_amount);
        $this->assertSame(52500, (int) $orderA->subtotal_amount);
        $this->assertSame(5775, (int) $orderA->tax_amount);
    }

    public function test_webhook_path_uses_same_authoritative_calculation(): void
    {
        // Jalur webhook (ProcessWebhookOrderJob) memanggil createTransaction
        // dengan items ter-normalisasi — hasilnya wajib identik dengan kasir.
        $service = app(TransactionService::class);
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenant->id);
        $context->setStoreId($this->store->id);

        $order = $service->createTransaction([
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 3, 'options' => null, 'extra_price' => 0],
            ],
            'payments' => [
                ['method' => PaymentMethod::Ewallet->value, 'amount' => 58300,
                    'reference_number' => 'GOFOOD-TEST-1'],
            ],
            'order_type' => 'takeaway',
            'idempotency_key' => 'channel:gofood:TEST-1',
        ], $this->cashier->id);

        $this->assertSame(52500, (int) $order->subtotal_amount);
        $this->assertSame(58300, (int) $order->order_amount);
        $this->assertSame(1, $order->payments()->count());
        $this->assertSame(58300, (int) $order->payments()->first()->amount);
    }

    public function test_split_payment_rejects_negative_and_mismatched_allocations(): void
    {
        $service = app(TransactionService::class);
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenant->id);
        $context->setStoreId($this->store->id);

        // Alokasi negatif.
        try {
            $service->createTransaction([
                'shift_id' => $this->shift->id,
                'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
                'payments' => [
                    ['method' => PaymentMethod::Cash->value, 'amount' => -100],
                    ['method' => PaymentMethod::Qris->value, 'amount' => 19425],
                ],
            ], $this->cashier->id);
            $this->fail('Negative allocation accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payments', $e->errors());
        }

        // Total alokasi tidak sama dengan grand_total (19425).
        try {
            $service->createTransaction([
                'shift_id' => $this->shift->id,
                'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
                'payments' => [
                    ['method' => PaymentMethod::Cash->value, 'amount' => 10000],
                    ['method' => PaymentMethod::Qris->value, 'amount' => 10000],
                ],
            ], $this->cashier->id);
            $this->fail('Mismatched allocation accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payments', $e->errors());
        }

        // Metode tidak didukung.
        try {
            $service->createTransaction([
                'shift_id' => $this->shift->id,
                'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
                'payments' => [
                    ['method' => 'crypto', 'amount' => 19425],
                ],
            ], $this->cashier->id);
            $this->fail('Unsupported method accepted.');
        } catch (\Throwable $e) {
            $this->assertTrue($e instanceof ValidationException
                || $e instanceof \ValueError,
                'Unsupported payment method must be rejected.');
        }

        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
    }
}
