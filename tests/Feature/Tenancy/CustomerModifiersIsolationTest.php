<?php

namespace Tests\Feature\Tenancy;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\Table;
use App\Models\Tenant;
use App\Services\Context\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CustomerModifiersIsolationTest extends TestCase
{
    use RefreshDatabase;

    private $tenantA;

    private $tenantB;

    private $storeA;

    private $storeB;

    private $tableA;

    private $tableB;

    private $productA;

    private $productB;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->runWithoutTenant(function () {
            $this->tenantA = Tenant::forceCreate(['name' => 'Tenant A']);
            $this->storeA = Store::forceCreate(['tenant_id' => $this->tenantA->id, 'name' => 'Store A']);
            $categoryA = Category::forceCreate(['tenant_id' => $this->tenantA->id, 'category_name' => 'Minuman A', 'category_code' => 'CAT-1']);
            $this->productA = Product::forceCreate([
                'tenant_id' => $this->tenantA->id,
                'category_id' => $categoryA->id,
                'product_name' => 'Kopi A',
                'product_code' => 'PRD-1',
                'product_price' => 15000,
                'stock' => 10,
                'is_active' => true,
            ]);
            $this->tableA = Table::forceCreate([
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA->id,
                'table_name' => 'Meja A',
                'table_code' => 'TBL-A',
                'secure_token' => 'token-a',
                'capacity' => 4,
                'is_active' => true,
            ]);

            Artisan::call('tenant:provision-system-users', ['tenant' => $this->tenantA->id]);

            $this->tenantB = Tenant::forceCreate(['name' => 'Tenant B']);
            $this->storeB = Store::forceCreate(['tenant_id' => $this->tenantB->id, 'name' => 'Store B']);
            $categoryB = Category::forceCreate(['tenant_id' => $this->tenantB->id, 'category_name' => 'Minuman B', 'category_code' => 'CAT-2']);
            $this->productB = Product::forceCreate([
                'tenant_id' => $this->tenantB->id,
                'category_id' => $categoryB->id,
                'product_name' => 'Kopi B',
                'product_code' => 'PRD-2',
                'product_price' => 20000,
                'stock' => 5,
                'is_active' => true,
            ]);
            $this->tableB = Table::forceCreate([
                'tenant_id' => $this->tenantB->id,
                'store_id' => $this->storeB->id,
                'table_name' => 'Meja B',
                'table_code' => 'TBL-B',
                'secure_token' => 'token-b',
                'capacity' => 2,
                'is_active' => true,
            ]);
        });
    }

    public function test_a_valid_table_session_loads_modifiers()
    {
        // 1. Establish session
        $this->get('/menu/'.$this->tableA->secure_token)->assertStatus(200);

        // 2. Fetch modifiers
        $response = $this->getJson('/menu/'.$this->productA->id.'/modifiers');

        $response->assertStatus(200);
        $response->assertJsonPath('product.id', $this->productA->id);
    }

    public function test_b_no_table_session_rejects_modifier_request_gracefully()
    {
        // Fresh request with NO previous table session
        $response = $this->getJson('/menu/'.$this->productA->id.'/modifiers');

        // EnsureTableSession returns 403 JSON when expectsJson()
        $response->assertStatus(403);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', 'Sesi meja tidak ditemukan. Silakan scan ulang QR Code.');
    }

    public function test_c_invalid_or_inactive_table_session_rejects_gracefully()
    {
        // 1. Establish session
        $this->get('/menu/'.$this->tableA->secure_token)->assertStatus(200);

        // Deactivate table
        app(TenantContext::class)->runWithoutTenant(function () {
            $this->tableA->update(['is_active' => false]);
        });

        // 2. Fetch modifiers
        $response = $this->getJson('/menu/'.$this->productA->id.'/modifiers');

        $response->assertStatus(403);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', 'Meja ini sudah tidak aktif. Silakan scan ulang QR Code.');
    }

    public function test_d_cross_tenant_isolation_protects_modifiers()
    {
        // 1. Establish session as Tenant A
        $this->get('/menu/'.$this->tableA->secure_token)->assertStatus(200);

        // 2. Attempt to fetch Tenant B's product
        $response = $this->getJson('/menu/'.$this->productB->id.'/modifiers');

        // TenantScope implicitly throws 404 because Product ID isn't found within Tenant A's scope
        $response->assertStatus(404);
    }

    public function test_e_qr_flow_cart_and_checkout_continue_working()
    {
        // 1. Establish session
        $this->get('/menu/'.$this->tableA->secure_token)->assertStatus(200);

        // 2. Add to cart
        $response = $this->postJson('/cart', [
            'product_id' => $this->productA->id,
            'qty' => 1,
            'notes' => '',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        // 3. Initiate Checkout to get idempotency key
        $checkoutPage = $this->get('/checkout');
        $checkoutPage->assertStatus(200);

        $idempotencyKey = session('checkout_idempotency_key');
        $this->assertNotNull($idempotencyKey);

        // 4. Submit Checkout
        $checkoutSubmit = $this->post('/checkout', [
            '_idempotency_key' => $idempotencyKey,
            'payment_method' => 'cash',
            'customer_name' => 'John Doe',
        ]);

        // Expect redirect to success page
        $checkoutSubmit->assertStatus(302);

        $this->assertDatabaseHas('orders', [
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'table_id' => $this->tableA->id,
            'order_type' => 'dine_in',
        ]);
    }

    public function test_f_middleware_priority_guarantees_context_before_binding()
    {
        // 1. We'll access a protected route with an implicit model binding
        // to prove that table session runs BEFORE implicit binding.
        // We know that `EnsureTableSession` triggers TenantContext assignment.
        // If it runs AFTER `SubstituteBindings`, the `TenantScope` will throw `RuntimeException`.

        // Since test A proves it by not throwing a 500 RuntimeException,
        // we assert that we can make a successful request where table.session
        // and implicit route-model binding are both present.
        $this->get('/menu/'.$this->tableA->secure_token)->assertStatus(200);

        // Fetch modifiers uses Product $product (SubstituteBindings) and table.session
        $response = $this->getJson('/menu/'.$this->productA->id.'/modifiers');

        // If this returns 200, SubstituteBindings successfully ran AFTER EnsureTableSession
        $response->assertStatus(200);
    }
}
