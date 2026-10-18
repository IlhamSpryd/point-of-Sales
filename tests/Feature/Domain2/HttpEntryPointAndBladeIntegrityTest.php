<?php

declare(strict_types=1);

namespace Tests\Feature\Domain2;

use App\Enums\PaymentMethod;
use App\Models\Discount;
use App\Models\Order;
use App\Models\Shift;
use App\Models\Table;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

/**
 * Domain 2: HTTP Entry Points, Blade Component & FormRequest Integrity Test.
 *
 * Menguji:
 * - Render halaman GET /orders (orders.index) dan GET /discounts (<x-discount-manager />)
 * - Penegakan OrderPolicy & ShiftPolicy pada TransactionController & ShiftController
 * - Validasi FormRequest terikat tenant_id & store_id (StoreTransactionRequest, StoreCheckoutRequest)
 */
class HttpEntryPointAndBladeIntegrityTest extends TestCase
{
    use RefreshDatabase;
    use TwoTenantFixture;

    protected bool $optOutFromDefaultTenant = true;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();
        $this->setupTwoTenants();
    }

    /**
     * Test 1: GET /orders me-render layout standar dan livewire riwayat pesanan tanpa 0-byte error.
     */
    public function test_orders_index_page_renders_with_layout_and_livewire_component(): void
    {
        $this->actingAs($this->userAOwner);
        app(TenantContext::class)->setTenantId($this->tenantA->id);
        app(TenantContext::class)->setStoreId($this->storeA->id);

        $response = $this->get(route('orders.index'));

        $response->assertOk();
        $response->assertViewIs('orders.index');
        $response->assertSee('Riwayat Pesanan');
        $response->assertSeeLivewire('kasir.order-history');
    }

    /**
     * Test 2: GET /discounts me-render komponen Blade discount-manager tanpa missing-component error.
     */
    public function test_discounts_index_page_renders_with_discount_manager_component(): void
    {
        $this->actingAs($this->userAOwner);
        app(TenantContext::class)->setTenantId($this->tenantA->id);
        app(TenantContext::class)->setStoreId($this->storeA->id);

        $response = $this->get(route('discounts.index'));

        $response->assertOk();
        $response->assertViewIs('discounts.index');
        $response->assertSee('Diskon & Promo');
        $response->assertSeeLivewire('discount-manager');
    }

    /**
     * Test 3: TransactionController otorisasi & isolasi tenant (OrderPolicy).
     */
    public function test_transaction_controller_authorization_and_cross_tenant_rejection(): void
    {
        $this->actingAs($this->userAOwner);
        app(TenantContext::class)->setTenantId($this->tenantA->id);
        app(TenantContext::class)->setStoreId($this->storeA->id);

        // 1. Order milik sendiri diakses dengan sukses
        $ownResponse = $this->get(route('orders.show', $this->orderA->order_code));
        $ownResponse->assertOk();

        // 2. Order milik Tenant B ditolak (403 atau 404 karena TenantScope)
        $crossResponse = $this->get(route('orders.show', $this->orderB->order_code));
        $this->assertTrue(in_array($crossResponse->status(), [403, 404], true));

        // 3. Void order milik Tenant B ditolak
        $voidResponse = $this->post(route('orders.void', $this->orderB->order_code), [
            'reason' => 'Cross-tenant illegal void attempt',
        ]);
        $this->assertTrue(in_array($voidResponse->status(), [403, 404], true));
    }

    /**
     * Test 4: ShiftController delegasi ShiftService & otorisasi ShiftPolicy.
     */
    public function test_shift_controller_lifecycle_and_cross_tenant_rejection(): void
    {
        $this->actingAs($this->userAOwner);
        app(TenantContext::class)->setTenantId($this->tenantA->id);
        app(TenantContext::class)->setStoreId($this->storeA->id);

        // 1. Buka shift via ShiftController
        $openResponse = $this->postJson(route('shifts.open'), [
            'opening_balance' => 50000,
        ]);
        $openResponse->assertOk();
        $openResponse->assertJson(['success' => true]);

        $shiftId = $openResponse->json('shift.id');
        $shift = Shift::withoutGlobalScopes()->find($shiftId);
        $this->assertNotNull($shift);
        $this->assertSame('open', $shift->status);
        $this->assertSame($this->tenantA->id, $shift->tenant_id);

        // 2. Catat cash movement
        $moveResponse = $this->postJson(route('shifts.movement', $shift->id), [
            'type' => 'cash_in',
            'amount' => 20000,
            'category' => 'restock_change',
            'reason' => 'Tambah modal kas',
        ]);
        $moveResponse->assertOk();

        // 3. Tutup shift
        $closeResponse = $this->postJson(route('shifts.close', $shift->id), [
            'closing_balance' => 70000,
            'notes' => 'Shift selesai dengan baik',
        ]);
        $closeResponse->assertOk();
        $this->assertSame('closed', Shift::withoutGlobalScopes()->find($shiftId)->status);

        // 4. Buat shift milik Tenant B, lalu coba tutup dari Tenant A -> HARUS ditolak (403/404)
        $shiftB = Shift::withoutTenantScope()->create([
            'tenant_id' => $this->tenantB->id,
            'store_id' => $this->storeB->id,
            'user_id' => $this->userBBarista->id,
            'status' => 'open',
            'opening_balance' => 100000,
            'opened_at' => now(),
        ]);

        $crossClose = $this->postJson(route('shifts.close', $shiftB->id), [
            'closing_balance' => 100000,
        ]);
        $this->assertTrue(in_array($crossClose->status(), [403, 404], true));
    }

    /**
     * Test 5: StoreTransactionRequest validasi tenant-scoped exists & PaymentMethod enum.
     */
    public function test_store_transaction_request_tenant_scoped_validation(): void
    {
        $this->actingAs($this->userAOwner);
        app(TenantContext::class)->setTenantId($this->tenantA->id);
        app(TenantContext::class)->setStoreId($this->storeA->id);

        // Buka shift aktif agar shift guard lolos
        $shift = Shift::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->userAOwner->id,
            'status' => 'open',
            'opening_balance' => 50000,
            'opened_at' => now(),
        ]);

        // 1. Kirim produk milik Tenant B (productB) -> HARUS 422 validation error
        $crossProductResponse = $this->postJson(route('transaction.store'), [
            'items' => [
                ['product_id' => $this->productB->id, 'quantity' => 1, 'extra_price' => 0],
            ],
            'payment_method' => PaymentMethod::Cash->value,
            'cash_received' => 100000,
            'shift_id' => $shift->id,
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $crossProductResponse->assertStatus(422);
        $crossProductResponse->assertJsonValidationErrors(['items.0.product_id']);

        // 2. Kirim metode pembayaran yang TIDAK ada di PaymentMethod enum -> HARUS 422
        $invalidPaymentResponse = $this->postJson(route('transaction.store'), [
            'items' => [
                ['product_id' => $this->productA->id, 'quantity' => 1, 'extra_price' => 0],
            ],
            'payment_method' => 'bitcoin_invalid',
            'cash_received' => 100000,
            'shift_id' => $shift->id,
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $invalidPaymentResponse->assertStatus(422);
        $invalidPaymentResponse->assertJsonValidationErrors(['payment_method']);
    }

    /**
     * Test 6: StoreCheckoutRequest validasi server-side checkout mandiri pelanggan.
     */
    public function test_store_checkout_request_validation(): void
    {
        app(TenantContext::class)->setTenantId($this->tenantA->id);
        app(TenantContext::class)->setStoreId($this->storeA->id);

        $tableA = Table::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'table_name' => 'MEJA-CK-1',
            'capacity' => 4,
            'operational_status' => 'available',
            'secure_token' => (string) Str::uuid(),
        ]);

        // Set session meja
        session([
            'current_table_id' => $tableA->id,
            'current_tenant_id' => $this->tenantA->id,
            'current_store_id' => $this->storeA->id,
        ]);

        // Kirim metode pembayaran tidak valid untuk self-order
        $response = $this->postJson(route('customer.checkout.store'), [
            'payment_method' => 'unsupported_method',
            'customer_name' => 'Budi Pelanggan',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payment_method']);
    }
}
