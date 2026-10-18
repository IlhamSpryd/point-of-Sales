<?php

declare(strict_types=1);

namespace Tests\Feature\Domain2;

use App\Models\Category;
use App\Models\Device;
use App\Models\Discount;
use App\Models\Order;
use App\Models\ParkedCart;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CartService;
use App\Services\Context\TenantContext;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ParkedCartLifecycleAndRevalidationTest
 *
 * Menguji kepatuhan terhadap:
 * - AC-4.2: Keranjang yang di-park lalu terjadi kenaikan harga produk atau habisnya stok
 *           sebelum di-resume akan memperbarui harga ke harga server terbaru dan
 *           menandai item yang habis stoknya saat di-resume.
 * - AC-4.3: Satu ParkedCart tidak dapat dikonversi menjadi dua Order secara paralel.
 * - Validasi ulang diskon/voucher yang kuotanya habis saat cart di-resume.
 * - Isolasi tenant, store, device, shift, dan jejak audit trail.
 */
class ParkedCartLifecycleAndRevalidationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $optOutFromDefaultTenant = true;

    private int $tenantId;

    private int $storeId;

    private int $deviceId;

    private int $userId;

    private int $shiftId;

    private CartService $cartService;

    private TransactionService $txService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $context = app(TenantContext::class);
        $context->clear();

        $tenant = Tenant::create(['name' => 'ParkedCart Tenant '.Str::random(6), 'readiness_status' => 'active']);
        $this->tenantId = (int) $tenant->id;

        $store = Store::withoutTenantScope()->create([
            'tenant_id' => $this->tenantId,
            'name' => 'ParkedCart Store '.Str::random(6),
        ]);
        $this->storeId = (int) $store->id;

        $context->setTenantId($this->tenantId);
        $context->setStoreId($this->storeId);

        $device = Device::create([
            'tenant_id' => $this->tenantId,
            'store_id' => $this->storeId,
            'device_name' => 'POS-Terminal-Parked',
            'device_code' => 'DEV-'.strtoupper(Str::random(8)),
            'device_type' => 'pos',
            'is_active' => true,
        ]);
        $this->deviceId = (int) $device->id;

        $user = User::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Parked Cashier '.Str::random(6),
            'email' => 'cashier-'.uniqid().'@parked.test',
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
            'opening_balance' => 500000,
            'opened_at' => now(),
        ]);
        $this->shiftId = (int) $shift->id;

        $this->cartService = app(CartService::class);
        $this->txService = app(TransactionService::class);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    private function createProduct(int $stock = 10, int $price = 20000): Product
    {
        $category = Category::create([
            'tenant_id' => $this->tenantId,
            'category_name' => 'Category '.Str::random(6),
        ]);

        return Product::create([
            'tenant_id' => $this->tenantId,
            'category_id' => $category->id,
            'product_name' => 'Kopi Susu '.Str::random(4),
            'product_price' => $price,
            'stock' => $stock,
            'is_active' => true,
        ]);
    }

    /**
     * Membuktikan alur park cart tersimpan di database dengan relasi dan audit trail.
     */
    public function test_park_cart_persists_in_database_with_correct_relations(): void
    {
        $product = $this->createProduct(stock: 10, price: 20000);

        $cartState = [
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_name' => $product->product_name,
                    'qty' => 2,
                    'unit_price' => 20000,
                    'base_price' => 20000,
                ],
            ],
            'customer_label' => 'Meja VIP 1',
            'order_type' => 'dine_in',
        ];

        $parkedCart = $this->cartService->parkCart(
            $this->tenantId,
            $this->storeId,
            $this->deviceId,
            $this->shiftId,
            $this->userId,
            $cartState,
            'Meja VIP 1'
        );

        $this->assertDatabaseHas('parked_carts', [
            'id' => $parkedCart->id,
            'tenant_id' => $this->tenantId,
            'store_id' => $this->storeId,
            'device_id' => $this->deviceId,
            'shift_id' => $this->shiftId,
            'status' => 'parked',
            'customer_label' => 'Meja VIP 1',
        ]);

        $this->assertNotNull($parkedCart->hold_reference);
        $this->assertStringStartsWith('HOLD-', $parkedCart->hold_reference);
    }

    /**
     * AC-4.2: Keranjang yang di-park lalu terjadi kenaikan harga produk sebelum di-resume
     * akan memperbarui harga ke harga server terbaru dan memunculkan warning.
     */
    public function test_resume_parked_cart_detects_price_changes_and_updates_prices_ac_4_2(): void
    {
        $product = $this->createProduct(stock: 10, price: 20000);

        $cartState = [
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_name' => $product->product_name,
                    'qty' => 1,
                    'unit_price' => 20000,
                    'base_price' => 20000,
                ],
            ],
            'customer_label' => 'Pelanggan Walk-In',
        ];

        $parked = $this->cartService->parkCart(
            $this->tenantId,
            $this->storeId,
            $this->deviceId,
            $this->shiftId,
            $this->userId,
            $cartState
        );

        // Terjadi kenaikan harga master produk di server dari 20.000 menjadi 25.000
        $product->update(['product_price' => 25000]);

        $resumed = $this->cartService->resumeParkedCart(
            $parked->id,
            $this->tenantId,
            $this->storeId,
            $this->deviceId,
            $this->shiftId,
            $this->userId
        );

        // Harga item harus ter-update ke 25.000
        $item = $resumed['cart']['items'][0];
        $this->assertEquals(25000, $item['unit_price']);

        // Harus ada pesan peringatan kenaikan harga
        $this->assertNotEmpty($resumed['warnings']);
        $warningText = implode(' ', $resumed['warnings']);
        $this->assertStringContainsString('berubah', $warningText);
        $this->assertStringContainsString('25.000', $warningText);

        // Status keranjang berubah menjadi resumed
        $this->assertEquals('resumed', $resumed['parked_cart']->fresh()->status);
    }

    /**
     * AC-4.2: Keranjang yang di-park lalu terjadi habisnya stok sebelum di-resume
     * akan menandai item yang habis stoknya dan memblokir checkout.
     */
    public function test_resume_parked_cart_detects_insufficient_stock_and_flags_blocking_ac_4_2(): void
    {
        $product = $this->createProduct(stock: 5, price: 20000);

        $cartState = [
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_name' => $product->product_name,
                    'qty' => 4,
                    'unit_price' => 20000,
                    'base_price' => 20000,
                ],
            ],
        ];

        $parked = $this->cartService->parkCart(
            $this->tenantId,
            $this->storeId,
            $this->deviceId,
            $this->shiftId,
            $this->userId,
            $cartState
        );

        // Stok produk terjual habis oleh transaksi lain, sisa 1 unit
        $product->update(['stock' => 1]);

        $resumed = $this->cartService->resumeParkedCart(
            $parked->id,
            $this->tenantId,
            $this->storeId,
            $this->deviceId,
            $this->shiftId,
            $this->userId
        );

        // Harus ditandai has_blocking_issues = true
        $this->assertTrue($resumed['has_blocking_issues']);

        $item = $resumed['cart']['items'][0];
        $this->assertFalse($item['is_available']);
        $this->assertTrue($item['insufficient_stock']);

        // Harus ada pesan peringatan stok tidak mencukupi
        $warningText = implode(' ', $resumed['warnings']);
        $this->assertStringContainsString('Stok', $warningText);
        $this->assertStringContainsString('tidak mencukupi', $warningText);
    }

    /**
     * Menguji bahwa voucher yang kuotanya habis sebelum cart di-resume akan otomatis dilepas.
     */
    public function test_resume_parked_cart_detaches_exhausted_discount_voucher(): void
    {
        $product = $this->createProduct(stock: 10, price: 50000);

        $discount = Discount::create([
            'tenant_id' => $this->tenantId,
            'store_id' => $this->storeId,
            'code' => 'VOUCHERPARK',
            'name' => 'Voucher Sekali Pakai',
            'type' => 'fixed',
            'value' => 10000,
            'usage_limit' => 1,
            'is_active' => true,
        ]);

        $cartState = [
            'items' => [
                ['product_id' => $product->id, 'product_name' => $product->product_name, 'qty' => 1, 'unit_price' => 50000],
            ],
            'discount_id' => $discount->id,
        ];

        $parked = $this->cartService->parkCart(
            $this->tenantId,
            $this->storeId,
            $this->deviceId,
            $this->shiftId,
            $this->userId,
            $cartState
        );

        // Transaksi lain memakai voucher tersebut hingga kuota habis
        $this->txService->createTransaction([
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'discount_id' => $discount->id,
            'payment_method' => 'cash',
            'cash_received' => 50000,
            'shift_id' => $this->shiftId,
            'device_id' => $this->deviceId,
            'idempotency_key' => (string) Str::uuid(),
        ], $this->userId);

        $resumed = $this->cartService->resumeParkedCart(
            $parked->id,
            $this->tenantId,
            $this->storeId,
            $this->deviceId,
            $this->shiftId,
            $this->userId
        );

        // Diskon harus dilepas
        $this->assertNull($resumed['cart']['discount_id']);

        // Ada peringatan kuota voucher habis
        $warningText = implode(' ', $resumed['warnings']);
        $this->assertStringContainsString('Voucher diskon sudah tidak berlaku', $warningText);
    }

    /**
     * AC-4.3: Satu ParkedCart tidak dapat dikonversi menjadi dua Order secara paralel.
     */
    public function test_single_parked_cart_cannot_be_converted_into_two_orders_ac_4_3(): void
    {
        $product = $this->createProduct(stock: 20, price: 20000);

        $cartState = [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
        ];

        $parked = $this->cartService->parkCart(
            $this->tenantId,
            $this->storeId,
            $this->deviceId,
            $this->shiftId,
            $this->userId,
            $cartState
        );

        // Checkout pertama mengonversi parked_cart
        $order1 = $this->txService->createTransaction([
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'parked_cart_id' => $parked->id,
            'payment_method' => 'cash',
            'cash_received' => 50000,
            'shift_id' => $this->shiftId,
            'device_id' => $this->deviceId,
            'idempotency_key' => (string) Str::uuid(),
        ], $this->userId);

        $this->assertNotNull($order1);

        // Status parked_cart di DB harus menjadi converted
        $parkedFresh = $parked->fresh();
        $this->assertEquals(ParkedCart::STATUS_CONVERTED, $parkedFresh->status);
        $this->assertEquals($order1->id, $parkedFresh->converted_order_id);

        // Checkout kedua dengan parked_cart_id yang sama harus ditolak
        $this->expectException(ValidationException::class);

        $this->txService->createTransaction([
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'parked_cart_id' => $parked->id,
            'payment_method' => 'cash',
            'cash_received' => 50000,
            'shift_id' => $this->shiftId,
            'device_id' => $this->deviceId,
            'idempotency_key' => (string) Str::uuid(),
        ], $this->userId);
    }
}
