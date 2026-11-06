<?php

declare(strict_types=1);

namespace Tests\Feature\Pos;

use App\Models\Category;
use App\Models\Device;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Sales\Services\OfflineOrderSyncService;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfflinePosSynchronizationTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Store $storeA;

    private Store $storeB;

    private User $cashierA;

    private Device $deviceA;

    private Product $productA;

    private Shift $shiftA;

    private OfflineOrderSyncService $syncService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        app(TenantContext::class)->runWithoutTenant(function () {
            // Tenant A
            $this->tenantA = Tenant::create([
                'name' => 'Offline POS Tenant A',
                'slug' => 'off-pos-a-'.uniqid(),
                'status' => 'active',
                'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
                'access_status' => Tenant::ACCESS_ACTIVE,
            ]);

            $this->storeA = Store::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Store A Offline Center',
                'code' => 'OFF-A-'.uniqid(),
                'is_active' => true,
            ]);

            $roleKasir = Role::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Kasir',
                'permissions' => ['pos.order', 'pos.shift'],
            ]);

            $this->cashierA = User::factory()->create([
                'tenant_id' => $this->tenantA->id,
                'role_id' => $roleKasir->id,
                'password' => Hash::make('password123'),
            ]);

            DB::table('user_stores')->insert([
                ['tenant_id' => $this->tenantA->id, 'user_id' => $this->cashierA->id, 'store_id' => $this->storeA->id],
            ]);

            $this->deviceA = Device::create([
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA->id,
                'device_name' => 'POS Terminal 01',
                'device_code' => 'OFF-TRM-01',
                'device_type' => 'pos_terminal',
                'device_secret' => 'off-secret-01',
                'is_active' => true,
            ]);

            $catA = Category::create([
                'tenant_id' => $this->tenantA->id,
                'category_name' => 'Menu Kasir Offline',
            ]);

            $this->productA = Product::create([
                'tenant_id' => $this->tenantA->id,
                'category_id' => $catA->id,
                'product_name' => 'Kopi Susu Gula Aren',
                'product_price' => 20000,
                'stock' => 100,
                'is_active' => true,
            ]);

            // Shift Aktif untuk Tenant A
            $this->shiftA = Shift::create([
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA->id,
                'user_id' => $this->cashierA->id,
                'active_cashier_id' => $this->cashierA->id,
                'device_id' => $this->deviceA->id,
                'opening_balance' => 100000,
                'status' => 'open',
                'opened_at' => now(),
            ]);

            // Tenant B
            $this->tenantB = Tenant::create([
                'name' => 'Offline POS Tenant B',
                'slug' => 'off-pos-b-'.uniqid(),
                'status' => 'active',
                'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
                'access_status' => Tenant::ACCESS_ACTIVE,
            ]);

            $this->storeB = Store::create([
                'tenant_id' => $this->tenantB->id,
                'name' => 'Store B Offline Center',
                'code' => 'OFF-B-'.uniqid(),
                'is_active' => true,
            ]);
        });

        app(TenantContext::class)->setTenantId($this->tenantA->id);
        app(TenantContext::class)->setStoreId($this->storeA->id);

        $this->syncService = app(OfflineOrderSyncService::class);
    }

    /**
     * DEC-006 / TR-003 Test 1:
     * Transaksi kasir luring dengan metode pembayaran TUNAI (CASH) berhasil disinkronkan secara atomik.
     */
    public function test_offline_order_with_cash_payment_is_synchronized_successfully(): void
    {
        $uuid = 'OFFLINE-'.(string) Str::uuid();

        $offlineBatch = [
            [
                'offline_client_uuid' => $uuid,
                'idempotency_key' => (string) Str::uuid(),
                'order_type' => 'takeaway',
                'payment_method' => 'cash',
                'order_amount' => 44400,
                'subtotal_amount' => 40000,
                'discount_amount' => 0,
                'tax_amount' => 4400,
                'cash_received' => 50000,
                'order_change' => 5600,
                'items' => [
                    [
                        'product_id' => $this->productA->id,
                        'quantity' => 2,
                        'unit_price' => 20000,
                        'subtotal' => 40000,
                    ],
                ],
            ],
        ];

        $response = $this->syncService->syncBatch(
            ordersData: $offlineBatch,
            userId: $this->cashierA->id,
            tenantId: $this->tenantA->id,
            storeId: $this->storeA->id,
            deviceId: $this->deviceA->id
        );

        $this->assertEquals(1, $response['synced_count']);
        $this->assertEquals(0, $response['failed_count']);
        $this->assertEquals('synced', $response['results'][0]['status']);

        // Verifikasi persistensi di database
        $order = Order::withoutGlobalScopes()->where('offline_client_uuid', $uuid)->first();
        $this->assertNotNull($order);
        $this->assertTrue($order->is_offline_sync);
        $this->assertNotNull($order->offline_synced_at);
        $this->assertEquals($this->tenantA->id, $order->tenant_id);
        $this->assertEquals(44400, $order->order_amount);

        // Verifikasi pembayaran terkait bertanda offline_sync
        $payment = Payment::withoutGlobalScopes()->where('order_id', $order->id)->first();
        $this->assertNotNull($payment);
        $this->assertTrue($payment->is_offline_sync);
        $this->assertNotNull($payment->offline_synced_at);
        $this->assertEquals(44400, $payment->amount);
    }

    /**
     * DEC-006 / TR-003 Test 2:
     * Transaksi kasir luring yang mencoba memakai metode pembayaran non-tunai (QRIS / EDC) wajib ditolak.
     */
    public function test_offline_order_rejects_non_cash_payment_methods(): void
    {
        $uuid = 'OFFLINE-'.(string) Str::uuid();

        $offlineBatch = [
            [
                'offline_client_uuid' => $uuid,
                'idempotency_key' => 'idemp-'.$uuid,
                'order_type' => 'takeaway',
                'payment_method' => 'qris', // Dilarang saat offline per DEC-006
                'order_amount' => 20000,
                'subtotal_amount' => 20000,
                'items' => [
                    [
                        'product_id' => $this->productA->id,
                        'quantity' => 1,
                        'unit_price' => 20000,
                        'subtotal' => 20000,
                    ],
                ],
            ],
        ];

        $response = $this->syncService->syncBatch(
            ordersData: $offlineBatch,
            userId: $this->cashierA->id,
            tenantId: $this->tenantA->id,
            storeId: $this->storeA->id,
            deviceId: $this->deviceA->id
        );

        $this->assertEquals(0, $response['synced_count']);
        $this->assertEquals(1, $response['failed_count']);
        $this->assertEquals('rejected', $response['results'][0]['status']);
        $this->assertStringContainsString('CASH only', $response['results'][0]['error']);

        // Pastikan tidak ada order yang terbuat
        $this->assertNull(Order::withoutGlobalScopes()->where('offline_client_uuid', $uuid)->first());
    }

    /**
     * DEC-006 / TR-003 Test 3:
     * Sinkronisasi transaksi offline bersifat idempoten penuh (retry sync tidak membuat order duplikat).
     */
    public function test_offline_order_synchronization_is_strictly_idempotent(): void
    {
        $uuid = 'OFFLINE-'.(string) Str::uuid();

        $offlineBatch = [
            [
                'offline_client_uuid' => $uuid,
                'idempotency_key' => (string) Str::uuid(),
                'order_type' => 'takeaway',
                'payment_method' => 'cash',
                'order_amount' => 22200,
                'subtotal_amount' => 20000,
                'tax_amount' => 2200,
                'cash_received' => 50000,
                'order_change' => 27800,
                'items' => [
                    [
                        'product_id' => $this->productA->id,
                        'quantity' => 1,
                        'unit_price' => 20000,
                        'subtotal' => 20000,
                    ],
                ],
            ],
        ];

        // Eksekusi sync pertama
        $res1 = $this->syncService->syncBatch($offlineBatch, $this->cashierA->id, $this->tenantA->id, $this->storeA->id, $this->deviceA->id);
        $this->assertEquals(1, $res1['synced_count']);
        $this->assertEquals('synced', $res1['results'][0]['status']);

        // Eksekusi sync kedua (simulasi network retry)
        $res2 = $this->syncService->syncBatch($offlineBatch, $this->cashierA->id, $this->tenantA->id, $this->storeA->id, $this->deviceA->id);
        $this->assertEquals(1, $res2['synced_count']);
        $this->assertEquals(0, $res2['failed_count']);
        $this->assertEquals('already_synced', $res2['results'][0]['status']);

        // Jumlah order dengan UUID tersebut di DB tetap tepat 1
        $count = Order::withoutGlobalScopes()->where('offline_client_uuid', $uuid)->count();
        $this->assertEquals(1, $count);
    }

    /**
     * DEC-006 / TR-003 Test 4:
     * Endpoint HTTP API /api/v1/pos/sync-offline-orders dapat diakses kasir dan merespons JSON valid.
     */
    public function test_offline_sync_http_endpoint_returns_json_response(): void
    {
        $uuid = 'OFFLINE-'.(string) Str::uuid();

        $payload = [
            'orders' => [
                [
                    'offline_client_uuid' => $uuid,
                    'payment_method' => 'cash',
                    'order_type' => 'takeaway',
                    'order_amount' => 22200,
                    'subtotal_amount' => 20000,
                    'tax_amount' => 2200,
                    'cash_received' => 50000,
                    'order_change' => 27800,
                    'items' => [
                        [
                            'product_id' => $this->productA->id,
                            'quantity' => 1,
                            'unit_price' => 20000,
                            'subtotal' => 20000,
                        ],
                    ],
                ],
            ],
            'device_id' => $this->deviceA->id,
        ];

        $response = $this->actingAs($this->cashierA)
            ->postJson(route('api.v1.pos.sync-offline-orders'), $payload);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.synced_count', 1)
            ->assertJsonPath('data.failed_count', 0);
    }

    /**
     * DEC-006 / TR-003 Test 5:
     * Sinkronisasi batch campuran menangani order valid dan tidak valid secara independen.
     */
    public function test_offline_batch_handles_mixed_valid_and_invalid_orders(): void
    {
        $uuidValid = 'OFFLINE-VALID-'.(string) Str::uuid();
        $uuidInvalid = 'OFFLINE-INVALID-'.(string) Str::uuid();

        $batch = [
            [
                'offline_client_uuid' => $uuidValid,
                'payment_method' => 'cash',
                'order_amount' => 22200,
                'subtotal_amount' => 20000,
                'tax_amount' => 2200,
                'cash_received' => 50000,
                'order_change' => 27800,
                'items' => [
                    ['product_id' => $this->productA->id, 'quantity' => 1, 'unit_price' => 20000, 'subtotal' => 20000],
                ],
            ],
            [
                'offline_client_uuid' => $uuidInvalid,
                'payment_method' => 'credit_card', // Non-cash, ditolak
                'order_amount' => 22200,
                'subtotal_amount' => 20000,
                'items' => [
                    ['product_id' => $this->productA->id, 'quantity' => 1, 'unit_price' => 20000, 'subtotal' => 20000],
                ],
            ],
        ];

        $response = $this->syncService->syncBatch($batch, $this->cashierA->id, $this->tenantA->id, $this->storeA->id, $this->deviceA->id);

        $this->assertEquals(1, $response['synced_count']);
        $this->assertEquals(1, $response['failed_count']);

        $this->assertNotNull(Order::withoutGlobalScopes()->where('offline_client_uuid', $uuidValid)->first());
        $this->assertNull(Order::withoutGlobalScopes()->where('offline_client_uuid', $uuidInvalid)->first());
    }
}
