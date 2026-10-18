<?php

declare(strict_types=1);

namespace Tests\Feature\Domain2;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatusEnum;
use App\Enums\ReturnDispositionEnum;
use App\Models\Category;
use App\Models\Device;
use App\Models\Ingredient;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductIngredient;
use App\Models\Role;
use App\Models\Shift;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Context\TenantContext;
use App\Services\ReturnRefundService;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

/**
 * OrderReturnAndDispositionTest
 *
 * Menguji kepatuhan terhadap:
 * - AC-3.1: Retur parsial 1 dari 3 item dengan disposisi restock menambah StockBalance
 *           tepat 1 unit, sedangkan disposisi write_off_waste atau quarantine menambah StockBalance sebesar 0 unit.
 * - Larangan Restock otomatis untuk produk F&B berbasis resep (BOM).
 * - INV-08: Batas maksimum retur per item: SUM(quantity_returned) <= order_item.qty.
 * - Idempotensi OrderReturn per idempotency_key.
 */
class OrderReturnAndDispositionTest extends TestCase
{
    use RefreshDatabase;
    use TwoTenantFixture;

    protected User $cashier;

    protected User $supervisor;

    protected Device $device;

    protected Shift $shift;

    protected Product $retailProduct;

    protected Product $recipeProduct;

    protected ReturnRefundService $service;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        app(TenantContext::class)->runWithoutTenant(function () {
            $this->setupTwoTenants();
            Model::unguard();

            $this->device = Device::create([
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA->id,
                'device_name' => 'POS Terminal Alpha',
                'device_code' => 'POS-ALPHA',
                'device_type' => Device::TYPE_POS_TERMINAL,
                'device_fingerprint' => 'fp-alpha-01',
                'status' => Device::STATUS_PAIRED,
                'is_active' => true,
            ]);

            $roleKasir = Role::withoutTenantScope()->firstOrCreate(
                ['name' => 'Kasir', 'tenant_id' => $this->tenantA->id],
                ['is_active' => 1]
            );

            $roleSupervisor = Role::withoutTenantScope()->firstOrCreate(
                ['name' => 'Supervisor', 'tenant_id' => $this->tenantA->id],
                ['is_active' => 1, 'permissions' => ['can_refund_order', 'can_return_order']]
            );

            $this->cashier = User::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Kasir Alpha',
                'email' => 'kasir.alpha@test.com',
                'password' => bcrypt('password'),
                'pin_hash' => bcrypt('123456'),
                'role_id' => $roleKasir->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
            $this->cashier->stores()->attach($this->storeA->id, ['tenant_id' => $this->tenantA->id]);

            $this->supervisor = User::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Supervisor Alpha',
                'email' => 'spv.alpha@test.com',
                'password' => bcrypt('password'),
                'pin_hash' => bcrypt('654321'),
                'role_id' => $roleSupervisor->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
            $this->supervisor->stores()->attach($this->storeA->id, ['tenant_id' => $this->tenantA->id]);

            $this->shift = Shift::create([
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA->id,
                'user_id' => $this->cashier->id,
                'device_id' => $this->device->id,
                'active_cashier_id' => $this->cashier->id,
                'opening_balance' => 500000,
                'status' => 'open',
                'opened_at' => now(),
            ]);

            $catRetail = Category::create([
                'tenant_id' => $this->tenantA->id,
                'category_name' => 'Retail Merch',
            ]);

            $catFnB = Category::create([
                'tenant_id' => $this->tenantA->id,
                'category_name' => 'Coffee & Beverages',
            ]);

            $this->retailProduct = Product::create([
                'tenant_id' => $this->tenantA->id,
                'category_id' => $catRetail->id,
                'product_name' => 'Tumbler Apeiron Limited',
                'product_price' => 100000,
                'stock' => 10,
            ]);

            $this->recipeProduct = Product::create([
                'tenant_id' => $this->tenantA->id,
                'category_id' => $catFnB->id,
                'product_name' => 'Specialty Cappuccino',
                'product_price' => 35000,
                'stock' => 50,
            ]);

            $ingredient = Ingredient::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Espresso Beans',
                'unit' => 'gr',
                'cost_per_unit' => 200,
                'current_stock' => '2000.0000',
            ]);

            ProductIngredient::create([
                'tenant_id' => $this->tenantA->id,
                'product_id' => $this->recipeProduct->id,
                'ingredient_id' => $ingredient->id,
                'quantity_required' => 18,
            ]);
        });

        app(TenantContext::class)->setTenantId($this->tenantA->id);
        app(TenantContext::class)->setStoreId($this->storeA->id);
        $this->service = app(ReturnRefundService::class);
    }

    /**
     * AC-3.1: Retur parsial 1 dari 3 item dengan disposisi restock menambah StockBalance
     * tepat 1 unit, sedangkan disposisi write_off_waste atau quarantine menambah StockBalance sebesar 0 unit.
     */
    public function test_partial_return_disposition_affects_stock_balance_accurately(): void
    {
        // Buat Order lunas dengan 3 unit tumbler
        $order = Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->cashier->id,
            'shift_id' => $this->shift->id,
            'created_by_device_id' => $this->device->id,
            'order_code' => 'ORD-RET-01',
            'idempotency_key' => 'idemp-ret-01',
            'order_date' => now()->toDateString(),
            'order_type' => 'take_away',
            'order_status' => OrderStatus::Paid,
            'payment_method' => 'cash',
            'subtotal_amount' => 300000,
            'order_amount' => 300000,
        ]);

        $orderItem = OrderItem::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'order_id' => $order->id,
            'product_id' => $this->retailProduct->id,
            'qty' => 3,
            'order_price' => 100000,
            'order_subtotal' => 300000,
        ]);

        $payment = Payment::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'order_id' => $order->id,
            'shift_id' => $this->shift->id,
            'device_id' => $this->device->id,
            'payment_method' => PaymentMethod::Cash,
            'amount' => 300000,
            'amount_tendered' => 300000,
            'change_amount' => 0,
            'status' => PaymentStatusEnum::Captured,
            'captured_at' => now(),
            'processed_by' => $this->cashier->id,
            'idempotency_key' => 'pay-ret-01',
        ]);

        $initialStock = (int) $this->retailProduct->fresh()->stock; // 10

        // 1. Kasus A: Retur 1 unit dengan disposisi 'restock'
        $returnA = $this->service->createReturnAndRefund(
            order: $order,
            returnItemsWithDisposition: [
                [
                    'order_item_id' => $orderItem->id,
                    'quantity' => 1,
                    'disposition' => ReturnDispositionEnum::Restock,
                    'disposition_reason' => 'Barang masih segel, ditukar varian lain',
                ],
            ],
            refundAllocations: [
                [
                    'payment_id' => $payment->id,
                    'amount' => 100000,
                    'refund_method' => 'cash',
                ],
            ],
            shiftId: $this->shift->id,
            deviceId: $this->device->id,
            actorUserId: $this->cashier->id,
            supervisorPin: '654321', // PIN Supervisor
            reason: 'Retur 1 unit restock',
            idempotencyKey: 'ret-key-restock-01'
        );

        $this->assertInstanceOf(OrderReturn::class, $returnA);
        $this->assertEquals(OrderReturn::TYPE_PARTIAL, $returnA->return_type);

        // Stok produk bertambah tepat 1 unit (10 + 1 = 11)
        $this->assertSame($initialStock + 1, (int) $this->retailProduct->fresh()->stock);

        // Verifikasi StockMovement terbentuk
        $this->assertDatabaseHas('stock_movements', [
            'order_id' => $order->id,
            'product_id' => $this->retailProduct->id,
            'quantity' => 1,
        ]);

        // 2. Kasus B: Retur 1 unit berikutnya dengan disposisi 'write_off_waste' (cacat pabrik)
        $returnB = $this->service->createReturnAndRefund(
            order: $order,
            returnItemsWithDisposition: [
                [
                    'order_item_id' => $orderItem->id,
                    'quantity' => 1,
                    'disposition' => ReturnDispositionEnum::WriteOffWaste,
                    'disposition_reason' => 'Tumbler bocor/rusak fisik',
                ],
            ],
            refundAllocations: [
                [
                    'payment_id' => $payment->id,
                    'amount' => 100000,
                    'refund_method' => 'cash',
                ],
            ],
            shiftId: $this->shift->id,
            deviceId: $this->device->id,
            actorUserId: $this->cashier->id,
            supervisorPin: '654321',
            reason: 'Retur barang rusak',
            idempotencyKey: 'ret-key-waste-02'
        );

        $this->assertInstanceOf(OrderReturn::class, $returnB);

        // Stok produk TIDAK BERUBAH (tetap 11, pertambahan 0 unit)
        $this->assertSame($initialStock + 1, (int) $this->retailProduct->fresh()->stock);

        // 3. Kasus C: Retur 1 unit terakhir dengan disposisi 'quarantine' (inspeksi)
        $returnC = $this->service->createReturnAndRefund(
            order: $order,
            returnItemsWithDisposition: [
                [
                    'order_item_id' => $orderItem->id,
                    'quantity' => 1,
                    'disposition' => ReturnDispositionEnum::Quarantine,
                    'disposition_reason' => 'Perlu pengecekan quality control',
                ],
            ],
            refundAllocations: [
                [
                    'payment_id' => $payment->id,
                    'amount' => 100000,
                    'refund_method' => 'cash',
                ],
            ],
            shiftId: $this->shift->id,
            deviceId: $this->device->id,
            actorUserId: $this->cashier->id,
            supervisorPin: '654321',
            reason: 'Retur untuk karantina',
            idempotencyKey: 'ret-key-quarantine-03'
        );

        $this->assertInstanceOf(OrderReturn::class, $returnC);
        $this->assertEquals(OrderReturn::TYPE_FULL, $returnC->return_type, 'Total kuantitas retur kini genap 3/3 (full)');

        // Stok produk TETAP 11 (tidak bertambah dari karantina)
        $this->assertSame($initialStock + 1, (int) $this->retailProduct->fresh()->stock);
    }

    /**
     * Larangan Restock: Produk F&B berbasis resep tidak boleh direstock ke inventori layak jual.
     */
    public function test_restock_disposition_is_forbidden_for_recipe_based_fnb_products(): void
    {
        $order = Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->cashier->id,
            'shift_id' => $this->shift->id,
            'created_by_device_id' => $this->device->id,
            'order_code' => 'ORD-RET-FNB-01',
            'idempotency_key' => 'idemp-ret-fnb-01',
            'order_date' => now()->toDateString(),
            'order_type' => 'dine_in',
            'order_status' => OrderStatus::Paid,
            'payment_method' => 'cash',
            'subtotal_amount' => 35000,
            'order_amount' => 35000,
        ]);

        $orderItem = OrderItem::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'order_id' => $order->id,
            'product_id' => $this->recipeProduct->id,
            'qty' => 1,
            'order_price' => 35000,
            'order_subtotal' => 35000,
        ]);

        $payment = Payment::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'order_id' => $order->id,
            'shift_id' => $this->shift->id,
            'device_id' => $this->device->id,
            'payment_method' => PaymentMethod::Cash,
            'amount' => 35000,
            'status' => PaymentStatusEnum::Captured,
            'captured_at' => now(),
            'processed_by' => $this->cashier->id,
            'idempotency_key' => 'pay-ret-fnb-01',
        ]);

        // Mencoba restock produk kopi F&B -> HARUS DITOLAK
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Larangan Restock: Produk F&B berbasis resep');

        $this->service->createReturnAndRefund(
            order: $order,
            returnItemsWithDisposition: [
                [
                    'order_item_id' => $orderItem->id,
                    'quantity' => 1,
                    'disposition' => ReturnDispositionEnum::Restock,
                ],
            ],
            refundAllocations: [
                [
                    'payment_id' => $payment->id,
                    'amount' => 35000,
                ],
            ],
            shiftId: $this->shift->id,
            deviceId: $this->device->id,
            actorUserId: $this->cashier->id,
            supervisorPin: '654321',
            reason: 'Salah racik kopi',
            idempotencyKey: 'ret-key-fnb-invalid'
        );
    }

    /**
     * INV-08: Kuantitas retur melebihi yang dibeli ditolak dengan DomainException.
     */
    public function test_return_quantity_exceeding_ordered_qty_is_rejected(): void
    {
        $order = Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->cashier->id,
            'shift_id' => $this->shift->id,
            'created_by_device_id' => $this->device->id,
            'order_code' => 'ORD-RET-LIMIT-01',
            'idempotency_key' => 'idemp-ret-limit-01',
            'order_date' => now()->toDateString(),
            'order_type' => 'take_away',
            'order_status' => OrderStatus::Paid,
            'payment_method' => 'cash',
            'subtotal_amount' => 100000,
            'order_amount' => 100000,
        ]);

        $orderItem = OrderItem::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'order_id' => $order->id,
            'product_id' => $this->retailProduct->id,
            'qty' => 1, // Beli 1
            'order_price' => 100000,
            'order_subtotal' => 100000,
        ]);

        $payment = Payment::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'order_id' => $order->id,
            'shift_id' => $this->shift->id,
            'device_id' => $this->device->id,
            'payment_method' => PaymentMethod::Cash,
            'amount' => 100000,
            'status' => PaymentStatusEnum::Captured,
            'captured_at' => now(),
            'processed_by' => $this->cashier->id,
            'idempotency_key' => 'pay-ret-limit-01',
        ]);

        // Coba retur 2 unit padahal beli cuma 1 -> HARUS DITOLAK
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('melebihi sisa yang dapat diretur');

        $this->service->createReturnAndRefund(
            order: $order,
            returnItemsWithDisposition: [
                [
                    'order_item_id' => $orderItem->id,
                    'quantity' => 2, // Melebihi qty beli
                    'disposition' => ReturnDispositionEnum::Restock,
                ],
            ],
            refundAllocations: [
                [
                    'payment_id' => $payment->id,
                    'amount' => 100000,
                ],
            ],
            shiftId: $this->shift->id,
            deviceId: $this->device->id,
            actorUserId: $this->cashier->id,
            supervisorPin: '654321',
            reason: 'Klaim retur berlebih',
            idempotencyKey: 'ret-key-excess'
        );
    }
}
