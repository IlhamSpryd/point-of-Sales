<?php

declare(strict_types=1);

namespace Tests\Feature\Kasir;

use App\Core\Context\TenantContext;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Models\ProductStockBalance;
use App\Modules\Organization\Models\Company;
use App\Modules\Organization\Models\Device;
use App\Modules\Organization\Models\Role;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Models\Tenant;
use App\Modules\Organization\Models\User;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\Shift;
use App\Platform\Documents\DocumentSequenceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Wave 10B — Jalur B Workstream 1 Regression Suite (P0-03 / P1-04).
 *
 * Membuktikan (RED sebelum fix):
 *  1. `pos-offline-queue.js` tidak lagi mendeklarasikan variabel `userId` yang hilang (ReferenceError strict-mode).
 *  2. Logout guard berjalan sinkron menggunakan cached pending count (bukan promise async),
 *     sehingga `e.preventDefault()` efektif menahan logout saat antrean luring masih ada.
 *  3. `OfflineOrderSyncService` memvalidasi `store_id` pada lookup shift (cross-store attribution ditolak).
 *  4. Kode order POS dihasilkan gap-free & sekuensial dari `DocumentSequenceService`.
 */
class Wave10BOfflineSyncHardeningTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenantA;

    private Company $companyA;

    private Store $storeA;

    private Store $storeB;

    private Device $deviceA;

    private User $cashierA;

    private Shift $shiftA;

    private Product $productA;

    protected function afterSubclassSetUp(): void
    {
        $this->tenantA = Tenant::create([
            'name' => 'Wave10B Tenant '.uniqid(),
            'slug' => 'wave10b-'.uniqid(),
            'status' => 'active',
            'access_status' => Tenant::ACCESS_ACTIVE,
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
        ]);

        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);

        $this->companyA = Company::create([
            'tenant_id' => $this->tenantA->id,
            'company_code' => 'CMP-W10B-'.uniqid(),
            'company_name' => 'Wave10B Company',
            'legal_name' => 'Wave10B Legal',
            'is_active' => true,
            'is_default' => true,
        ]);
        $context->setCompanyId($this->companyA->id);

        $this->storeA = Store::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Branch A',
            'is_active' => true,
        ]);

        $this->storeB = Store::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Branch B',
            'is_active' => true,
        ]);

        $context->setStoreId($this->storeA->id);

        $role = Role::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Kasir',
            'permissions' => ['*'],
        ]);

        $this->cashierA = User::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Cashier Wave10B',
            'email' => 'wave10b.cashier.'.uniqid().'@test.internal',
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'store_id' => $this->storeA->id,
            'is_active' => true,
        ]);

        DB::table('user_stores')->insert([
            'tenant_id' => $this->tenantA->id,
            'user_id' => $this->cashierA->id,
            'store_id' => $this->storeA->id,
        ]);

        $this->deviceA = Device::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'device_code' => 'DEV-W10B-'.uniqid(),
            'device_name' => 'POS Terminal Wave10B',
            'device_type' => 'pos',
            'is_active' => true,
        ]);

        $this->shiftA = Shift::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->cashierA->id,
            'active_cashier_id' => $this->cashierA->id,
            'status' => 'open',
            'opened_at' => now(),
            'starting_cash' => 200000,
        ]);

        $category = Category::create([
            'tenant_id' => $this->tenantA->id,
            'category_name' => 'Wave10B Category',
        ]);

        $this->productA = Product::create([
            'tenant_id' => $this->tenantA->id,
            'category_id' => $category->id,
            'product_code' => 'W10B-PRD-'.uniqid(),
            'product_name' => 'Kopi Wave 10B',
            'product_price' => 25000,
            'stock' => 40,
            'track_inventory' => true,
            'is_active' => true,
            'status' => 'active',
        ]);

        DB::table('product_stock_balances')
            ->where('tenant_id', $this->tenantA->id)
            ->where('store_id', $this->storeA->id)
            ->where('product_id', $this->productA->id)
            ->update(['quantity' => 40]);
    }

    public function test_offline_queue_resolves_declared_user_id_and_synchronous_logout_guard(): void
    {
        $js = File::get(public_path('js/pos-offline-queue.js'));

        // RED (sebelum fix): `parseInt(userId, 10)` memakai variabel yang tidak pernah dideklarasikan.
        $this->assertStringContainsString(
            'const userId = getActiveUserId();',
            $js,
            'pos-offline-queue.js harus mendeklarasikan userId sebelum parseInt(userId, 10) (P0-03 ReferenceError).'
        );
        $redeclarationCount = preg_match_all('/const userId = getActiveUserId\(\);/', $js);
        $this->assertSame(
            2,
            $redeclarationCount,
            'userId dideklarasikan sekali di openDatabase() dan sekali di enqueueOrder() (P0-03).'
        );

        // Logout guard harus SINKRON: membaca cached count dari localStorage, bukan .then() async.
        $this->assertStringContainsString('apeiron_pos_pending_count_${tenantId}', $js);
        $this->assertStringContainsString(
            'localStorage.getItem(`apeiron_pos_pending_count_${tenantId}`)',
            $js,
            'Logout guard wajib memakai cached pending count sinkron agar e.preventDefault() efektif.'
        );
        $this->assertMatchesRegularExpression(
            '/if \(count > 0\) \{\s*const ok = window\.confirm\([^)]*\);\s*if \(!ok\) \{\s*e\.preventDefault\(\);/s',
            $js,
            'e.preventDefault() harus dipanggil di dalam blok sinkron saat count > 0.'
        );
    }

    public function test_offline_capture_wiring_and_service_worker_shell_precache(): void
    {
        $blade = File::get(resource_path('views/livewire/pos/partials/checkout-modal.blade.php'));
        $sw = File::get(public_path('sw.js'));

        $this->assertStringContainsString(
            'window.ApeironOfflinePos.enqueueOrder',
            $blade,
            'Checkout POS wajib fallback ke ApeironOfflinePos.enqueueOrder() saat offline (P0-03).'
        );
        $this->assertStringContainsString('pos-offline-order-queued', $blade);

        $this->assertStringContainsString("'/js/pos-offline-queue.js'", $sw, 'Service Worker harus mem-precache entry offline queue POS.');
    }

    public function test_offline_sync_rejects_cross_store_shift_attribution(): void
    {
        // Shift milik Branch B (tenant sama) — payload sync dikirim dari Branch A.
        app(TenantContext::class)->setStoreId($this->storeB->id);
        $foreignShift = Shift::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeB->id,
            'user_id' => $this->cashierA->id,
            'status' => 'closed',
            'opened_at' => now()->subHours(8),
            'closed_at' => now()->subHour(),
            'starting_cash' => 100000,
        ]);
        app(TenantContext::class)->setStoreId($this->storeA->id);

        $uuid = 'OFFLINE-CROSSSTORE-'.(string) Str::uuid();

        $response = $this->actingAs($this->cashierA)
            ->withSession(['active_store_id' => $this->storeA->id])
            ->postJson(route('api.v1.pos.sync-offline-orders'), [
                'orders' => [[
                    'offline_client_uuid' => $uuid,
                    'payment_method' => 'cash',
                    'order_amount' => 25000,
                    'subtotal_amount' => 25000,
                    'cash_received' => 25000,
                    'offline_shift_id' => $foreignShift->id,
                    'items' => [
                        ['product_id' => $this->productA->id, 'quantity' => 1, 'unit_price' => 25000, 'subtotal' => 25000],
                    ],
                ]],
                'device_id' => $this->deviceA->id,
            ]);

        $response->assertStatus(200);

        $order = Order::withoutGlobalScopes()->where('offline_client_uuid', $uuid)->first();
        $this->assertNotNull($order, 'Order offline tetap tersinkronisasi (tidak dibuang).');

        $this->assertSame(
            $this->storeA->id,
            (int) $order->store_id,
            'Order harus tetap berada di store aktif pengirim.'
        );
        $this->assertNotSame(
            $foreignShift->id,
            (int) $order->shift_id,
            'Shift lintas-store (tenant sama) TIDAK boleh dipakai sebagai atrubusi order (P1-04).'
        );
        $this->assertSame(
            $this->shiftA->id,
            (int) $order->shift_id,
            'Order harus jatuh ke shift terbuka di store aktif.'
        );
    }

    public function test_offline_sync_is_idempotent_per_client_uuid(): void
    {
        $uuid = 'OFFLINE-DUP-'.(string) Str::uuid();

        $payload = [
            'orders' => [[
                'offline_client_uuid' => $uuid,
                'payment_method' => 'cash',
                'order_amount' => 50000,
                'subtotal_amount' => 50000,
                'cash_received' => 50000,
                'items' => [
                    ['product_id' => $this->productA->id, 'quantity' => 2, 'unit_price' => 25000, 'subtotal' => 50000],
                ],
            ]],
            'device_id' => $this->deviceA->id,
        ];

        $this->actingAs($this->cashierA)
            ->withSession(['active_store_id' => $this->storeA->id])
            ->postJson(route('api.v1.pos.sync-offline-orders'), $payload)
            ->assertStatus(200)
            ->assertJsonPath('data.synced_count', 1);

        $this->actingAs($this->cashierA)
            ->withSession(['active_store_id' => $this->storeA->id])
            ->postJson(route('api.v1.pos.sync-offline-orders'), $payload)
            ->assertStatus(200);

        $this->assertSame(1, Order::withoutGlobalScopes()->where('offline_client_uuid', $uuid)->count());

        $stock = (int) ProductStockBalance::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantA->id)
            ->where('store_id', $this->storeA->id)
            ->where('product_id', $this->productA->id)
            ->value('quantity');

        $this->assertSame(38, $stock, 'Replay duplikat tidak boleh memotong stok dua kali.');
    }

    public function test_pos_order_codes_are_gap_free_and_sequential_per_tenant(): void
    {
        $service = app(DocumentSequenceService::class);

        $first = $service->nextOrderCode((int) $this->tenantA->id, (int) $this->storeA->id, '202612');
        $second = $service->nextOrderCode((int) $this->tenantA->id, (int) $this->storeA->id, '202612');
        $otherStore = $service->nextOrderCode((int) $this->tenantA->id, (int) $this->storeB->id, '202612');

        $this->assertSame('POS-202612-'.$this->storeA->id.'-0001', $first);
        $this->assertSame('POS-202612-'.$this->storeA->id.'-0002', $second);
        $this->assertSame('POS-202612-'.$this->storeB->id.'-0001', $otherStore);

        $checkoutSource = File::get(app_path('Modules/Sales/Application/Handlers/CheckoutOrderHandler.php'));
        $this->assertStringNotContainsString(
            "'POS-'.".strtoupper('Str').'::random(6)',
            $checkoutSource,
            'CheckoutOrderHandler tidak boleh lagi memakai generator acak POS-XXXXXX-NNN (P1-04).'
        );
        $this->assertStringContainsString('nextOrderCode', $checkoutSource);
    }
}
