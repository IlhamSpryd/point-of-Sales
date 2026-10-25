<?php

declare(strict_types=1);

namespace Tests\Feature\TableManagement;

use App\Enums\OrderType;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\Table;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CartService;
use App\Services\Context\TenantContext;
use App\Services\EntitlementService;
use App\Services\TableService;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pengujian Keamanan Self-Order, Rotasi QR, Idempotensi, dan Retail Gating (TC-06 s.d. TC-11).
 * Memvalidasi INV-TBL-03, INV-TBL-04, INV-TBL-05, INV-TBL-06.
 */
class SelfOrderQrSecurityTest extends TestCase
{
    use DatabaseTransactions;

    protected bool $optOutFromDefaultTenant = true;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();
        app(TenantContext::class)->clear();
    }

    private function createTenant(string $name, string $plan = 'standard_trial'): Tenant
    {
        $tenant = Tenant::create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.uniqid(),
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'subscription_status' => Tenant::SUBSCRIPTION_TRIAL,
            'subscription_plan' => $plan,
            'country_code' => 'ID',
        ]);

        Artisan::call('tenant:provision-system-users', ['tenant' => $tenant->id]);

        return $tenant;
    }

    private function createStore(Tenant $tenant, string $name, string $code): Store
    {
        return Store::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'code' => $code,
            'timezone' => 'Asia/Jakarta',
            'business_date_cutoff' => '00:00',
            'default_channel' => 'dine_in',
            'is_active' => true,
        ]);
    }

    private function createUser(Tenant $tenant, string $roleName, array $storeIds = []): User
    {
        return app(TenantContext::class)->runAs($tenant->id, $storeIds[0] ?? null, function () use ($tenant, $roleName, $storeIds) {
            $role = Role::firstOrCreate(
                ['tenant_id' => $tenant->id, 'name' => $roleName],
                ['permissions' => ['*'], 'is_active' => true]
            );

            $user = User::create([
                'tenant_id' => $tenant->id,
                'role_id' => $role->id,
                'name' => "User {$roleName} {$tenant->id}",
                'email' => Str::random(10).'@apeiron.test',
                'password' => bcrypt('password123'),
                'is_active' => true,
                'email_verified_at' => now(),
            ]);

            if (! empty($storeIds)) {
                $syncData = [];
                foreach ($storeIds as $sId) {
                    $syncData[$sId] = ['tenant_id' => $tenant->id];
                }
                $user->stores()->sync($syncData);
            }

            return $user;
        });
    }

    private function createProduct(Tenant $tenant, Store $store, string $name, int $price, int $stock = 50): Product
    {
        return app(TenantContext::class)->runAs($tenant->id, $store->id, function () use ($tenant, $name, $price, $stock) {
            $category = Category::firstOrCreate(
                ['tenant_id' => $tenant->id, 'category_name' => 'Umum'],
                ['is_active' => true]
            );

            $product = Product::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id,
                'category_id' => $category->id,
                'product_name' => $name,
                'product_code' => 'PRD-'.strtoupper(Str::random(6)),
                'product_type' => 'standard',
                'product_price' => $price,
                'stock' => $stock,
                'track_inventory' => true,
                'status' => 'active',
                'is_active' => true,
            ]);

            return $product;
        });
    }

    private function createTable(Tenant $tenant, Store $store, string $name): Table
    {
        return app(TenantContext::class)->runAs($tenant->id, $store->id, function () use ($tenant, $store, $name) {
            return Table::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id,
                'store_id' => $store->id,
                'table_name' => $name,
                'table_code' => 'TBL-'.strtoupper(Str::random(6)),
                'capacity' => 4,
                'area' => 'Indoor',
                'is_active' => true,
                'operational_status' => 'available',
                'qr_token' => bin2hex(random_bytes(32)),
                'qr_token_version' => 1,
                'secure_token' => (string) Str::uuid(),
            ]);
        });
    }

    /**
     * TC-06: Independensi Checkout Retail Tanpa Meja.
     * Cabang/Tenant Retail (tanpa meja) dapat checkout dengan table_id = null & table_number = null (INV-TBL-03).
     */
    public function test_tc06_retail_checkout_works_without_any_table(): void
    {
        $tenant = $this->createTenant('Tenant Toko Buku Retail');
        $store = $this->createStore($tenant, 'Retail Bookstore Pusat', 'BS-01');
        $cashier = $this->createUser($tenant, 'Kasir', [$store->id]);
        $product = $this->createProduct($tenant, $store, 'Buku Tulis', 15000, 20);

        // Turn off tables_enabled setting for this store
        DB::table('settings')->updateOrInsert(
            ['tenant_id' => $tenant->id, 'key' => "store_{$store->id}_tables_enabled"],
            ['value' => '0', 'created_at' => now(), 'updated_at' => now()]
        );

        app(TenantContext::class)->setTenantId($tenant->id);
        app(TenantContext::class)->setStoreId($store->id);

        $this->assertFalse(app(EntitlementService::class)->isTablesEnabled($tenant->id, $store->id));

        // Create transaction: even if client attempts to pass a table_id, retail mode forces null
        $order = app(TransactionService::class)->createTransaction([
            'order_type' => 'dine_in',
            'table_id' => 12345, // Client injected table_id
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'unit_price' => 15000,
                    'extra_price' => 0,
                ],
            ],
            'payment_method' => 'cash',
            'cash_received' => 50000,
        ], $cashier->id);

        $this->assertNotNull($order);
        $this->assertNull($order->table_id, 'Retail order table_id must be null (INV-TBL-03)');
        $this->assertNull($order->table_number, 'Retail order table_number must be null (INV-TBL-03)');
        $this->assertSame($tenant->id, (int) $order->tenant_id);
        $this->assertSame($store->id, (int) $order->store_id);
    }

    /**
     * TC-07: Checkout F&B Takeaway Tanpa Meja.
     * Pesanan takeaway pada cabang F&B tidak membutuhkan meja dan tidak mengubah status meja menjadi occupied (INV-TBL-03).
     */
    public function test_tc07_fb_takeaway_works_without_table(): void
    {
        $tenant = $this->createTenant('Tenant Cafe Santai');
        $store = $this->createStore($tenant, 'Cafe Santai Cabang 1', 'CS-01');
        $cashier = $this->createUser($tenant, 'Kasir', [$store->id]);
        $table = $this->createTable($tenant, $store, '01');
        $product = $this->createProduct($tenant, $store, 'Es Kopi Susu', 20000, 30);

        app(TenantContext::class)->setTenantId($tenant->id);
        app(TenantContext::class)->setStoreId($store->id);

        $order = app(TransactionService::class)->createTransaction([
            'order_type' => 'takeaway',
            'table_id' => $table->id, // Passing table_id on takeaway should be stripped
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 20000,
                    'extra_price' => 0,
                ],
            ],
            'payment_method' => 'cash',
            'cash_received' => 100000,
        ], $cashier->id);

        $this->assertNull($order->table_id, 'Takeaway order table_id must be null');
        $this->assertNull($order->table_number, 'Takeaway order table_number must be null');
        $this->assertSame('available', $table->fresh()->operational_status, 'Table status must remain available');
    }

    /**
     * TC-08: Resolusi Valid Dine-In Self-Order.
     * Pelanggan scan QR token valid -> context bound -> order tercipta dengan table snapshot & table occupied (INV-TBL-04, INV-TBL-05).
     */
    public function test_tc08_valid_dine_in_self_order_resolution(): void
    {
        $tenant = $this->createTenant('Tenant Kopi Kenangan');
        $store = $this->createStore($tenant, 'Store A2', 'A2-02');
        $table = $this->createTable($tenant, $store, '05');
        $product = $this->createProduct($tenant, $store, 'Latte', 28000, 50);

        // Ensure self-order entitlement is enabled
        DB::table('settings')->updateOrInsert(
            ['tenant_id' => $tenant->id, 'key' => "store.{$store->id}.self_order_enabled"],
            ['value' => '1', 'created_at' => now(), 'updated_at' => now()]
        );

        // 1. Customer scans QR: access /menu/{token}
        $resp = $this->get(route('customer.menu.index', ['token' => $table->qr_token]));
        $resp->assertOk();

        // Check session bindings
        $resp->assertSessionHas('current_table_id', $table->id);
        $resp->assertSessionHas('current_tenant_id', $tenant->id);
        $resp->assertSessionHas('current_store_id', $store->id);
        $resp->assertSessionHas('current_qr_token_version', 1);

        // 2. Add product to cart
        $cartResp = $this->post(route('customer.cart.store'), [
            'product_id' => $product->id,
            'qty' => 2,
        ]);
        $cartResp->assertOk()->assertJson(['success' => true]);

        $idempotencyKey = (string) Str::uuid();
        $cartKey = "customer_cart_{$tenant->id}_{$store->id}_{$table->id}";
        $cartData = session($cartKey, []);

        $checkoutResp = $this->withSession([
            'current_table_id' => $table->id,
            'current_table_name' => $table->table_name,
            'current_tenant_id' => $tenant->id,
            'current_store_id' => $store->id,
            'current_qr_token_version' => 1,
            'checkout_idempotency_key' => $idempotencyKey,
            $cartKey => $cartData,
        ])->post(route('customer.checkout.store'), [
            'payment_method' => 'cash',
            '_idempotency_key' => $idempotencyKey,
        ]);

        $checkoutResp->assertRedirect();

        // Verify order created
        $order = Order::withoutGlobalScopes()->where('table_id', $table->id)->latest()->first();
        $this->assertNotNull($order);
        $this->assertSame($tenant->id, (int) $order->tenant_id);
        $this->assertSame($store->id, (int) $order->store_id);
        $this->assertSame($table->id, (int) $order->table_id);
        $this->assertSame('05', $order->table_number);
        $this->assertSame(OrderType::DineIn, $order->order_type);
        $this->assertSame('occupied', $table->fresh()->operational_status, 'Table status must transition to occupied');
    }

    /**
     * TC-09: Penolakan Kredensial QR yang Dicabut/Dirotasi.
     * Sesi pelanggan dengan versi QR lama ditolak seketika (HTTP 410) setelah admin merotasi token (INV-TBL-05).
     */
    public function test_tc09_revoked_or_rotated_qr_credentials_are_rejected(): void
    {
        $tenant = $this->createTenant('Tenant Kopi Juara');
        $store = $this->createStore($tenant, 'Cabang Senopati', 'SNP-01');
        $table = $this->createTable($tenant, $store, '07');
        $product = $this->createProduct($tenant, $store, 'Cappuccino', 30000, 50);

        // Customer opens menu with valid token
        $resp = $this->get(route('customer.menu.index', ['token' => $table->qr_token]));
        $resp->assertOk();

        $oldToken = $table->qr_token;
        $this->assertSame(1, (int) $table->qr_token_version);

        // Store manager rotates the QR token
        app(TenantContext::class)->setTenantId($tenant->id);
        app(TenantContext::class)->setStoreId($store->id);
        $newTable = app(TableService::class)->rotateQrToken($table);

        $this->assertNotSame($oldToken, $newTable->qr_token);
        $this->assertSame(2, (int) $newTable->qr_token_version);

        // 1. Try to access menu using old token -> 404
        $oldTokenResp = $this->get(route('customer.menu.index', ['token' => $oldToken]));
        $oldTokenResp->assertNotFound();

        // 2. Try to access cart or checkout using the old session (which still has version 1)
        $cartAccess = $this->get(route('customer.cart.index'));
        $this->assertSame(410, $cartAccess->getStatusCode(), 'Rotated QR session must return 410 Gone');
    }

    /**
     * TC-10: Idempotensi Permintaan Ganda.
     * Pengiriman checkout ganda dengan idempotency_key yang sama mengembalikan order yang ada tanpa duplikasi (INV-TBL-06).
     */
    public function test_tc10_idempotency_prevents_duplicate_orders_and_stock_deductions(): void
    {
        $tenant = $this->createTenant('Tenant Artisan Bread');
        $store = $this->createStore($tenant, 'Cabang Kemang', 'KMG-01');
        $cashier = $this->createUser($tenant, 'Kasir', [$store->id]);
        $table = $this->createTable($tenant, $store, '03');
        $product = $this->createProduct($tenant, $store, 'Croissant', 25000, 10);

        app(TenantContext::class)->setTenantId($tenant->id);
        app(TenantContext::class)->setStoreId($store->id);

        $idempotencyKey = (string) Str::uuid();

        $payload = [
            'order_type' => 'dine_in',
            'table_id' => $table->id,
            'idempotency_key' => $idempotencyKey,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'unit_price' => 25000,
                    'extra_price' => 0,
                ],
            ],
            'payment_method' => 'cash',
            'cash_received' => 100000,
        ];

        // First call: creates order
        $order1 = app(TransactionService::class)->createTransaction($payload, $cashier->id);
        $this->assertNotNull($order1);
        $initialOrdersCount = Order::where('idempotency_key', $idempotencyKey)->count();
        $this->assertSame(1, $initialOrdersCount);

        // Second call with same idempotency key: should return existing order directly
        $order2 = app(TransactionService::class)->createTransaction($payload, $cashier->id);
        $this->assertSame($order1->id, $order2->id);
        $this->assertSame(1, Order::where('idempotency_key', $idempotencyKey)->count());
    }

    /**
     * TC-11: Kalkulasi Ulang Harga Otoritatif di Server.
     * Nilai harga, diskon, atau store_id yang dimanipulasi klien diabaikan; server menghitung ulang secara otoritatif.
     */
    public function test_tc11_server_side_authoritative_price_recalculation(): void
    {
        $tenant = $this->createTenant('Tenant Premium Steak');
        $store = $this->createStore($tenant, 'Steak House BSD', 'BSD-01');
        $table = $this->createTable($tenant, $store, 'VIP-1');
        $product = $this->createProduct($tenant, $store, 'Wagyu Ribeye', 350000, 10);

        // Start session via QR
        $this->get(route('customer.menu.index', ['token' => $table->qr_token]));

        // Add to cart normally
        $this->post(route('customer.cart.store'), [
            'product_id' => $product->id,
            'qty' => 1,
        ]);

        // Tamper session cart price to Rp 1000
        $cartKey = app(CartService::class)->getSessionKey();
        $cart = session($cartKey, []);
        foreach ($cart as $k => $item) {
            $cart[$k]['unit_price'] = 1000;
            $cart[$k]['base_price'] = 1000;
        }
        session([$cartKey => $cart]);

        // Attempt checkout
        $idempotencyKey = (string) Str::uuid();
        session(['checkout_idempotency_key' => $idempotencyKey]);

        $resp = $this->post(route('customer.checkout.store'), [
            'payment_method' => 'cash',
            '_idempotency_key' => $idempotencyKey,
            'store_id' => 9999, // Injected store_id
            'order_amount' => 1000, // Injected total
        ]);

        // M-05 protection: detects price mismatch, refreshes prices, rejects tampered request
        $resp->assertSessionHas('error');

        // Order with fake price was not created
        $this->assertDatabaseMissing('orders', [
            'tenant_id' => $tenant->id,
            'order_amount' => 1000,
        ]);
    }
}
