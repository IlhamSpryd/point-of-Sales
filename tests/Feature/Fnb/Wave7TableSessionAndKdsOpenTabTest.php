<?php

declare(strict_types=1);

namespace Tests\Feature\Fnb;

use App\Core\Context\TenantContext;
use App\Enums\OrderStatus;
use App\Enums\PreparationStatus;
use App\Livewire\Kds\Board;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Organization\Models\Role;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Models\Tenant;
use App\Modules\Organization\Models\User;
use App\Modules\Organization\Services\RoleService;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderItem;
use App\Modules\Sales\Models\Table;
use App\Modules\Sales\Models\TableSession;
use App\Modules\Sales\Services\KdsService;
use App\Modules\Sales\Services\TableService;
use App\Modules\Subscription\Models\Plan;
use App\Modules\Subscription\Models\TenantSubscription;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Wave 7 Checkpoint 7B Verification:
 * F&B Table Sessions, Anti-replay QR Self-Order Security & KDS Open-Tab Unblocking.
 */
class Wave7TableSessionAndKdsOpenTabTest extends TestCase
{
    use DatabaseTransactions;

    protected bool $optOutFromDefaultTenant = true;

    protected Tenant $fnbTenant;

    protected Store $fnbStore;

    protected Table $fnbTable;

    protected User $fnbStaff;

    protected function afterSubclassSetUp(): void
    {
        $this->fnbTenant = Tenant::create([
            'name' => 'F&B Test Tenant '.uniqid(),
            'code' => 'FNB-'.strtoupper(Str::random(6)),
            'subdomain' => 'fnb-'.strtolower(Str::random(6)),
            'primary_industry' => 'fnb',
            'business_subtype' => 'restaurant',
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
        ]);

        $this->fnbStore = Store::create([
            'tenant_id' => $this->fnbTenant->id,
            'name' => 'Flagship Bistro',
            'code' => 'STR-'.strtoupper(Str::random(4)),
            'store_vertical' => 'fnb',
            'default_channel' => 'dine_in',
            'is_active' => true,
        ]);

        // Provision plan & entitlement for self-order
        $plan = Plan::firstOrCreate(
            ['code' => 'pro'],
            ['name' => 'Pro Plan', 'is_active' => true, 'price' => 500000, 'billing_cycle' => 'monthly']
        );

        TenantSubscription::create([
            'tenant_id' => $this->fnbTenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addYear(),
        ]);

        $context = app(TenantContext::class);
        $context->setTenantId($this->fnbTenant->id);
        $context->setStoreId($this->fnbStore->id);

        app(RoleService::class)->provisionDefaultRoles($this->fnbTenant);
        $cookRole = Role::where('tenant_id', $this->fnbTenant->id)->where('name', 'Cook')->first()
            ?? Role::where('tenant_id', $this->fnbTenant->id)->where('name', 'Owner')->firstOrFail();

        $this->fnbStaff = User::create([
            'name' => 'Chef Pierre',
            'email' => 'chef.'.uniqid().'@bistro.test',
            'password' => bcrypt('Secret123!'),
            'role_id' => $cookRole->id,
            'tenant_id' => $this->fnbTenant->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $this->fnbTable = Table::create([
            'tenant_id' => $this->fnbTenant->id,
            'store_id' => $this->fnbStore->id,
            'table_code' => 'T-01',
            'table_name' => 'Table 01',
            'capacity' => 4,
            'area' => 'Indoor Main Hall',
            'is_active' => true,
            'operational_status' => 'available',
            'qr_token' => bin2hex(random_bytes(32)),
            'qr_token_version' => 1,
            'secure_token' => (string) Str::uuid(),
        ]);
    }

    /**
     * 1. Static Table QR Code remains unchanged, while dynamic table_sessions are generated.
     */
    public function test_static_qr_token_remains_stable_and_generates_dynamic_table_sessions(): void
    {
        $originalQrToken = $this->fnbTable->qr_token;
        $this->assertNotEmpty($originalQrToken);

        // Guest 1 scans table QR
        $response1 = $this->get('/menu/'.$originalQrToken);
        $response1->assertOk();
        $response1->assertSessionHas('current_table_id', $this->fnbTable->id);
        $response1->assertSessionHas('current_table_session_id');
        $response1->assertSessionHas('current_table_session_token');

        $sessionId1 = session('current_table_session_id');
        $sessionToken1 = session('current_table_session_token');

        $sessionRow1 = app(TenantContext::class)->runAs((int) $this->fnbTenant->id, (int) $this->fnbStore->id, function () use ($sessionId1) {
            return TableSession::find($sessionId1);
        });
        $this->assertNotNull($sessionRow1);
        $this->assertSame('active', $sessionRow1->status);
        $this->assertSame($this->fnbTable->id, $sessionRow1->table_id);
        $this->assertSame($sessionToken1, $sessionRow1->session_token);

        // Table is released by cashier
        $tableService = app(TableService::class);
        $tableService->releaseTable($this->fnbTable);

        // Verify: qr_token on table did NOT rotate! (Protects physical acrylic sticker)
        $this->fnbTable->refresh();
        $this->assertSame($originalQrToken, $this->fnbTable->qr_token);

        // Verify: session 1 was closed
        app(TenantContext::class)->runAs((int) $this->fnbTenant->id, (int) $this->fnbStore->id, function () use ($sessionRow1) {
            $sessionRow1->refresh();
            $this->assertSame('closed', $sessionRow1->status);
            $this->assertNotNull($sessionRow1->closed_at);
        });

        // Guest 2 scans the same static QR
        $this->flushSession();
        $response2 = $this->get('/menu/'.$originalQrToken);
        $response2->assertOk();
        $response2->assertSessionHas('current_table_session_id');

        $sessionId2 = session('current_table_session_id');
        $this->assertNotSame($sessionId1, $sessionId2);

        $sessionRow2 = app(TenantContext::class)->runAs((int) $this->fnbTenant->id, (int) $this->fnbStore->id, function () use ($sessionId2) {
            return TableSession::find($sessionId2);
        });
        $this->assertNotNull($sessionRow2);
        $this->assertSame('active', $sessionRow2->status);
    }

    /**
     * 2. Replaying a closed session from outside the restaurant is rejected with 410.
     */
    public function test_replaying_closed_table_session_from_outside_restaurant_is_rejected_with_410(): void
    {
        // Establish initial session
        $this->get('/menu/'.$this->fnbTable->qr_token)->assertOk();

        $sessionId = session('current_table_session_id');
        $sessionToken = session('current_table_session_token');
        $this->assertNotNull($sessionId);

        // Accessing cart while session is active succeeds
        $this->get('/cart')->assertOk();

        // Cashier releases table (e.g. guest pays and leaves)
        app(TableService::class)->releaseTable($this->fnbTable);

        // Guest tries to access /cart again with their old session token -> rejected 410 Gone!
        $response = $this->get('/cart');
        $response->assertStatus(410);
    }

    /**
     * 3. Retail outlet rejects QR self-order scan with 403.
     */
    public function test_retail_store_rejects_qr_self_order_access_with_403(): void
    {
        app(TenantContext::class)->clear();

        $retailTenant = Tenant::create([
            'name' => 'Retail Grocery Mart '.uniqid(),
            'code' => 'RTL-'.strtoupper(Str::random(6)),
            'subdomain' => 'rtl-'.strtolower(Str::random(6)),
            'primary_industry' => 'retail',
            'business_subtype' => 'minimarket',
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
        ]);

        $retailStore = app(TenantContext::class)->runAs((int) $retailTenant->id, null, function () use ($retailTenant) {
            $store = Store::create([
                'tenant_id' => $retailTenant->id,
                'name' => 'Grocery Outlet 1',
                'code' => 'RTL-'.strtoupper(Str::random(4)),
                'store_vertical' => 'retail',
                'default_channel' => 'counter_retail',
                'is_active' => true,
            ]);

            Table::create([
                'tenant_id' => $retailTenant->id,
                'store_id' => $store->id,
                'table_code' => 'RTL-TBL',
                'table_name' => 'Counter Bar 01',
                'capacity' => 2,
                'is_active' => true,
                'operational_status' => 'available',
                'qr_token' => bin2hex(random_bytes(32)),
                'qr_token_version' => 1,
                'secure_token' => (string) Str::uuid(),
            ]);

            return $store;
        });

        $retailTable = Table::withoutGlobalScopes()->where('store_id', $retailStore->id)->firstOrFail();

        $response = $this->get('/menu/'.$retailTable->qr_token);
        $response->assertStatus(403);
    }

    /**
     * 4. KDS unblocks authorized Dine-In Open-Tab and blocks unpaid QRIS Pay-First.
     */
    public function test_kds_unblocks_authorized_dine_in_open_tab_and_blocks_unpaid_qris_pay_first(): void
    {
        $category = Category::create([
            'tenant_id' => $this->fnbTenant->id,
            'store_id' => $this->fnbStore->id,
            'name' => 'Hot Mains',
            'kitchen_station' => 'kitchen',
        ]);

        $product = Product::create([
            'tenant_id' => $this->fnbTenant->id,
            'store_id' => $this->fnbStore->id,
            'category_id' => $category->id,
            'name' => 'Ribeye Steak 250g',
            'price' => 175000,
            'sku' => 'STK-001',
            'kitchen_station' => 'kitchen',
        ]);

        // Scenario A: Dine-in Open-Tab (pay_later, sent_to_kitchen_at set)
        $openTabOrder = Order::forceCreate([
            'tenant_id' => $this->fnbTenant->id,
            'store_id' => $this->fnbStore->id,
            'user_id' => $this->fnbStaff->id,
            'order_code' => 'ORD-OPEN-'.rand(100, 999),
            'idempotency_key' => (string) Str::uuid(),
            'order_date' => now()->toDateString(),
            'order_type' => 'dine_in',
            'origin_channel' => 'pos_cashier',
            'payment_method' => 'cash',
            'payment_timing' => 'pay_later',
            'sent_to_kitchen_at' => now(),
            'order_status' => OrderStatus::Pending,
            'table_id' => $this->fnbTable->id,
            'table_number' => $this->fnbTable->table_name,
            'subtotal_amount' => 175000,
            'tax_amount' => 17500,
            'order_amount' => 192500,
        ]);

        $openTabItem = OrderItem::create([
            'tenant_id' => $this->fnbTenant->id,
            'store_id' => $this->fnbStore->id,
            'order_id' => $openTabOrder->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 175000,
            'subtotal' => 175000,
            'preparation_status' => PreparationStatus::Pending,
        ]);

        // Scenario B: Unpaid QRIS Pay-First order (has snap_token / pay_first without payment settlement)
        $qrisPendingOrder = Order::forceCreate([
            'tenant_id' => $this->fnbTenant->id,
            'store_id' => $this->fnbStore->id,
            'user_id' => $this->fnbStaff->id,
            'order_code' => 'ORD-QRIS-'.rand(100, 999),
            'idempotency_key' => (string) Str::uuid(),
            'order_date' => now()->toDateString(),
            'order_type' => 'dine_in',
            'origin_channel' => 'qr_table',
            'payment_method' => 'qris',
            'payment_timing' => 'pay_first',
            'snap_token' => 'snap-test-token-'.rand(100, 999),
            'sent_to_kitchen_at' => null,
            'order_status' => OrderStatus::Pending,
            'table_id' => $this->fnbTable->id,
            'table_number' => $this->fnbTable->table_name,
            'subtotal_amount' => 175000,
            'tax_amount' => 17500,
            'order_amount' => 192500,
        ]);

        $qrisPendingItem = OrderItem::create([
            'tenant_id' => $this->fnbTenant->id,
            'store_id' => $this->fnbStore->id,
            'order_id' => $qrisPendingOrder->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 175000,
            'subtotal' => 175000,
            'preparation_status' => PreparationStatus::Pending,
        ]);

        // Set tenant context for staff
        $context = app(TenantContext::class);
        $context->setTenantId($this->fnbTenant->id);
        $context->setStoreId($this->fnbStore->id);

        $this->actingAs($this->fnbStaff);

        // KDS Board verification: Open-tab item is visible, unpaid QRIS is NOT visible
        Livewire::test(Board::class)
            ->assertSee('ORD-OPEN-')
            ->assertDontSee('ORD-QRIS-');

        // KdsService processing:
        $kdsService = app(KdsService::class);

        // Open-tab item can be claimed and marked ready
        $claimedItem = $kdsService->claim($openTabItem->id, $this->fnbStaff->id);
        $this->assertSame(PreparationStatus::Brewing, $claimedItem->preparation_status);
        $this->assertSame($this->fnbStaff->id, $claimedItem->processed_by);

        $readyItem = $kdsService->markReady($openTabItem->id, $this->fnbStaff->id);
        $this->assertSame(PreparationStatus::Ready, $readyItem->preparation_status);

        // Unpaid QRIS Pay-First item throws ValidationException when claimed
        $this->expectException(ValidationException::class);
        $kdsService->claim($qrisPendingItem->id, $this->fnbStaff->id);
    }
}
