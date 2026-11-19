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
use App\Modules\Sales\Models\Payment;
use App\Modules\Sales\Models\Shift;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature Test: Offline POS Idempotent Replay, Cash-Only Invariants, and Multi-Tenant Resilience (EXT-05, R-11, DEC-006).
 */
class OfflinePosIdempotentReplayTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenantA;

    private Store $storeA;

    private Company $companyA;

    private Device $deviceA;

    private User $cashierA;

    private Shift $shiftA;

    private Product $productA;

    private Tenant $tenantB;

    private Store $storeB;

    protected function afterSubclassSetUp(): void
    {
        // 1. Setup Tenant A
        $this->tenantA = Tenant::create([
            'name' => 'Offline POS Tenant A '.uniqid(),
            'slug' => 'pos-a-'.uniqid(),
            'status' => 'active',
            'access_status' => Tenant::ACCESS_ACTIVE,
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
        ]);

        app(TenantContext::class)->setTenantId($this->tenantA->id);

        $this->companyA = Company::create([
            'tenant_id' => $this->tenantA->id,
            'company_code' => 'CMP-A-'.uniqid(),
            'company_name' => 'Company A',
            'legal_name' => 'Legal A',
            'is_active' => true,
            'is_default' => true,
        ]);

        app(TenantContext::class)->setCompanyId($this->companyA->id);

        $this->storeA = Store::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Store A',
            'is_active' => true,
        ]);

        app(TenantContext::class)->setStoreId($this->storeA->id);

        $roleA = Role::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Kasir',
            'permissions' => ['*'],
        ]);

        $this->cashierA = User::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Cashier A',
            'email' => 'cashier.a.'.uniqid().'@test.internal',
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
            'role_id' => $roleA->id,
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
            'device_code' => 'DEV-A-'.uniqid(),
            'device_name' => 'POS Terminal A',
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

        $categoryA = Category::create([
            'tenant_id' => $this->tenantA->id,
            'category_name' => 'Main Category A',
        ]);

        $this->productA = Product::create([
            'tenant_id' => $this->tenantA->id,
            'category_id' => $categoryA->id,
            'product_code' => 'PRD-A-'.uniqid(),
            'product_name' => 'Kopi Tubruk Nusantara',
            'product_price' => 25000,
            'stock' => 50,
            'track_inventory' => true,
            'is_active' => true,
            'status' => 'active',
        ]);

        DB::table('product_stock_balances')
            ->where('tenant_id', $this->tenantA->id)
            ->where('store_id', $this->storeA->id)
            ->where('product_id', $this->productA->id)
            ->update(['quantity' => 50]);

        // 2. Setup Tenant B (for cross-tenant attacks)
        $this->tenantB = Tenant::create([
            'name' => 'Offline POS Tenant B '.uniqid(),
            'slug' => 'pos-b-'.uniqid(),
            'status' => 'active',
            'access_status' => Tenant::ACCESS_ACTIVE,
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
        ]);

        app(TenantContext::class)->setTenantId($this->tenantB->id);

        $this->storeB = Store::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Store B Foreign',
            'is_active' => true,
        ]);

        // Restore context to Tenant A
        app(TenantContext::class)->setTenantId($this->tenantA->id);
        app(TenantContext::class)->setCompanyId($this->companyA->id);
        app(TenantContext::class)->setStoreId($this->storeA->id);
    }

    /**
     * Invariant 1: Cash-Only Offline Rule (DEC-006).
     * Rejects non-cash payment methods at root level with HTTP 422,
     * and isolates non-cash order items during batch processing.
     */
    public function test_cash_only_offline_rule_enforcement_and_rejection(): void
    {
        // 1. Root-level non-cash payment_method is rejected with HTTP 422
        $payloadWithRootNonCash = [
            'payment_method' => 'qris',
            'orders' => [
                [
                    'offline_client_uuid' => 'OFFLINE-'.(string) Str::uuid(),
                    'payment_method' => 'cash',
                    'order_amount' => 25000,
                    'subtotal_amount' => 25000,
                    'items' => [
                        ['product_id' => $this->productA->id, 'quantity' => 1, 'unit_price' => 25000],
                    ],
                ],
            ],
        ];

        $resRoot = $this->actingAs($this->cashierA)
            ->withSession(['active_store_id' => $this->storeA->id])
            ->postJson(route('api.v1.pos.sync-offline-orders'), $payloadWithRootNonCash);

        $resRoot->assertStatus(422);

        // 2. Order-level non-cash item in batch is rejected cleanly without DB insertion
        $uuidNonCash = 'OFFLINE-NONCASH-'.(string) Str::uuid();
        $payloadBatchNonCash = [
            'orders' => [
                [
                    'offline_client_uuid' => $uuidNonCash,
                    'payment_method' => 'credit_card',
                    'order_amount' => 25000,
                    'subtotal_amount' => 25000,
                    'items' => [
                        ['product_id' => $this->productA->id, 'quantity' => 1, 'unit_price' => 25000],
                    ],
                ],
            ],
        ];

        $resBatch = $this->actingAs($this->cashierA)
            ->withSession(['active_store_id' => $this->storeA->id])
            ->postJson(route('api.v1.pos.sync-offline-orders'), $payloadBatchNonCash);

        $resBatch->assertStatus(200);
        $this->assertSame('partial', $resBatch->json('status'));
        $this->assertSame(1, $resBatch->json('data.failed_count'));
        $this->assertStringContainsString('CASH only', (string) $resBatch->json('data.results.0.error'));

        // Zero records created
        $this->assertNull(Order::withoutGlobalScopes()->where('offline_client_uuid', $uuidNonCash)->first());
    }

    /**
     * Invariant 2: Idempotent Batch Replay.
     * Submitting duplicate offline_client_uuid returns deterministic idempotent success
     * without creating duplicate orders, duplicate payments, or double stock deduction.
     */
    public function test_idempotent_batch_replay_prevents_duplicate_orders_and_stock_deduction(): void
    {
        $uuid = 'OFFLINE-IDEMP-'.(string) Str::uuid();

        $payload = [
            'orders' => [
                [
                    'offline_client_uuid' => $uuid,
                    'payment_method' => 'cash',
                    'order_amount' => 50000,
                    'subtotal_amount' => 50000,
                    'cash_received' => 100000,
                    'order_change' => 50000,
                    'items' => [
                        ['product_id' => $this->productA->id, 'quantity' => 2, 'unit_price' => 25000, 'subtotal' => 50000],
                    ],
                ],
            ],
            'device_id' => $this->deviceA->id,
        ];

        // First Sync Pass
        $res1 = $this->actingAs($this->cashierA)
            ->withSession(['active_store_id' => $this->storeA->id])
            ->postJson(route('api.v1.pos.sync-offline-orders'), $payload);

        $res1->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.synced_count', 1)
            ->assertJsonPath('data.failed_count', 0);

        $firstOrderId = $res1->json('data.results.0.order_id');
        $this->assertNotNull($firstOrderId);

        // Initial stock was 50, deducted by 2 -> 48
        $stockAfterFirst = (int) ProductStockBalance::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantA->id)
            ->where('store_id', $this->storeA->id)
            ->where('product_id', $this->productA->id)
            ->value('quantity');
        $this->assertSame(48, $stockAfterFirst);

        // Orders and payments counts
        $this->assertSame(1, Order::withoutGlobalScopes()->where('offline_client_uuid', $uuid)->count());
        $this->assertSame(1, Payment::withoutGlobalScopes()->where('order_id', $firstOrderId)->count());

        // Second Sync Pass (Simulated network retry / duplicate replay)
        $res2 = $this->actingAs($this->cashierA)
            ->withSession(['active_store_id' => $this->storeA->id])
            ->postJson(route('api.v1.pos.sync-offline-orders'), $payload);

        $res2->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.synced_count', 1)
            ->assertJsonPath('data.failed_count', 0);

        // Status reported must indicate skipped/already synced
        $reportedStatus = $res2->json('data.results.0.status');
        $this->assertTrue(in_array($reportedStatus, ['already_synced', 'duplicate_skipped'], true));

        // Exact same order ID returned
        $this->assertSame($firstOrderId, $res2->json('data.results.0.order_id'));

        // ZERO double deduction: stock must remain EXACTLY 48
        $stockAfterSecond = (int) ProductStockBalance::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantA->id)
            ->where('store_id', $this->storeA->id)
            ->where('product_id', $this->productA->id)
            ->value('quantity');
        $this->assertSame(48, $stockAfterSecond);

        // ZERO duplicate order or payment
        $this->assertSame(1, Order::withoutGlobalScopes()->where('offline_client_uuid', $uuid)->count());
        $this->assertSame(1, Payment::withoutGlobalScopes()->where('order_id', $firstOrderId)->count());
    }

    /**
     * Invariant 3: Strict Multi-Tenant Isolation.
     * Ignores/rejects forged tenant_id or foreign store_id in offline payloads (TenantContext authoritative).
     */
    public function test_strict_multi_tenant_isolation_rejects_cross_tenant_spoofing(): void
    {
        // 1. Explicit cross-tenant tenant_id parameter is rejected with 422
        $crossTenantPayload = [
            'tenant_id' => $this->tenantB->id,
            'orders' => [
                [
                    'offline_client_uuid' => 'OFFLINE-'.(string) Str::uuid(),
                    'payment_method' => 'cash',
                    'order_amount' => 25000,
                    'subtotal_amount' => 25000,
                    'items' => [
                        ['product_id' => $this->productA->id, 'quantity' => 1, 'unit_price' => 25000],
                    ],
                ],
            ],
        ];

        $resCrossTenant = $this->actingAs($this->cashierA)
            ->withSession(['active_store_id' => $this->storeA->id])
            ->postJson(route('api.v1.pos.sync-offline-orders'), $crossTenantPayload);

        $resCrossTenant->assertStatus(422)
            ->assertJsonPath('message', 'Cross-tenant payload rejected.');

        // 2. Foreign store_id belonging to another tenant is rejected with 422
        $foreignStorePayload = [
            'store_id' => $this->storeB->id, // Store B belongs to Tenant B
            'orders' => [
                [
                    'offline_client_uuid' => 'OFFLINE-'.(string) Str::uuid(),
                    'payment_method' => 'cash',
                    'order_amount' => 25000,
                    'subtotal_amount' => 25000,
                    'items' => [
                        ['product_id' => $this->productA->id, 'quantity' => 1, 'unit_price' => 25000],
                    ],
                ],
            ],
        ];

        $resForeignStore = $this->actingAs($this->cashierA)
            ->withSession(['active_store_id' => $this->storeB->id])
            ->postJson(route('api.v1.pos.sync-offline-orders'), $foreignStorePayload);

        $resForeignStore->assertStatus(422)
            ->assertJsonPath('message', 'Store does not belong to active tenant.');

        // 3. Embedded forged tenant_id in order item is neutralized by authoritative TenantContext
        $embeddedSpoofUuid = 'OFFLINE-EMBEDDED-'.(string) Str::uuid();
        $embeddedPayload = [
            'orders' => [
                [
                    'offline_client_uuid' => $embeddedSpoofUuid,
                    'tenant_id' => $this->tenantB->id, // Forged
                    'payment_method' => 'cash',
                    'order_amount' => 25000,
                    'subtotal_amount' => 25000,
                    'cash_received' => 50000,
                    'order_change' => 25000,
                    'items' => [
                        ['product_id' => $this->productA->id, 'quantity' => 1, 'unit_price' => 25000, 'subtotal' => 25000],
                    ],
                ],
            ],
        ];

        $resEmbedded = $this->actingAs($this->cashierA)
            ->withSession(['active_store_id' => $this->storeA->id])
            ->postJson(route('api.v1.pos.sync-offline-orders'), $embeddedPayload);

        $resEmbedded->assertStatus(200)->assertJsonPath('status', 'success');

        $createdOrder = Order::withoutGlobalScopes()->where('offline_client_uuid', $embeddedSpoofUuid)->first();
        $this->assertNotNull($createdOrder);
        // Authoritative context enforces Tenant A, completely ignoring the forged Tenant B
        $this->assertSame($this->tenantA->id, (int) $createdOrder->tenant_id);
    }

    /**
     * Invariant 4: Partial Batch Resilience.
     * When 1 item in a multi-order batch fails validation (e.g., non-existent product),
     * valid orders in the batch are committed and reported as synced, while failed orders
     * are reported with error details so only confirmed UUIDs can be purged by client IndexedDB.
     */
    public function test_partial_batch_resilience_handles_mixed_valid_and_failing_orders(): void
    {
        $validUuid = 'OFFLINE-PARTIAL-VALID-'.(string) Str::uuid();
        $failingUuid = 'OFFLINE-PARTIAL-FAIL-'.(string) Str::uuid();

        $batchPayload = [
            'orders' => [
                [
                    'offline_client_uuid' => $validUuid,
                    'payment_method' => 'cash',
                    'order_amount' => 25000,
                    'subtotal_amount' => 25000,
                    'cash_received' => 50000,
                    'order_change' => 25000,
                    'items' => [
                        ['product_id' => $this->productA->id, 'quantity' => 1, 'unit_price' => 25000, 'subtotal' => 25000],
                    ],
                ],
                [
                    'offline_client_uuid' => $failingUuid,
                    'payment_method' => 'cash',
                    'order_amount' => 25000,
                    'subtotal_amount' => 25000,
                    'items' => [
                        ['product_id' => 99999999, 'quantity' => 1, 'unit_price' => 25000], // Non-existent product
                    ],
                ],
            ],
            'device_id' => $this->deviceA->id,
        ];

        $response = $this->actingAs($this->cashierA)
            ->withSession(['active_store_id' => $this->storeA->id])
            ->postJson(route('api.v1.pos.sync-offline-orders'), $batchPayload);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'partial')
            ->assertJsonPath('data.synced_count', 1)
            ->assertJsonPath('data.failed_count', 1);

        $results = $response->json('data.results');
        $this->assertCount(2, $results);

        // Result 0: Valid order synced
        $this->assertSame($validUuid, $results[0]['offline_client_uuid']);
        $this->assertSame('synced', $results[0]['status']);
        $this->assertNotNull(Order::withoutGlobalScopes()->where('offline_client_uuid', $validUuid)->first());

        // Result 1: Failing order rejected/failed
        $this->assertSame($failingUuid, $results[1]['offline_client_uuid']);
        $this->assertTrue(in_array($results[1]['status'], ['failed', 'rejected'], true));
        $this->assertNotEmpty($results[1]['error']);
        $this->assertNull(Order::withoutGlobalScopes()->where('offline_client_uuid', $failingUuid)->first());
    }
}
