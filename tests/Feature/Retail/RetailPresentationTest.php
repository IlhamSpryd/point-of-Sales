<?php

declare(strict_types=1);

namespace Tests\Feature\Retail;

use App\Enums\OrderStatus;
use App\Livewire\Retail\BarcodeManager;
use App\Livewire\Retail\RetailExchangeManager;
use App\Livewire\Retail\ShelfLabelManager;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Retail\Models\ShelfLabel;
use App\Modules\Retail\Services\RetailBarcodeService;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class RetailPresentationTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;

    private Store $store;

    private Role $role;

    private User $user;

    private Product $product;

    private ProductVariant $variantA;

    private ProductVariant $variantB;

    protected function afterSubclassSetUp(): void
    {
        $this->tenant = Tenant::create([
            'name' => 'Retail Fashion Enterprise '.uniqid(),
            'code' => 'RTL-'.strtoupper(Str::random(4)),
            'slug' => 'rtl-'.uniqid(),
            'status' => 'ACTIVE',
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Fashion Store Mall Kelapa Gading',
            'code' => 'STR-'.substr(uniqid(), 0, 10),
            'is_active' => true,
        ]);

        app(TenantContext::class)->setStoreId($this->store->id);

        $this->role = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Owner',
            'permissions' => ['*'],
        ]);

        $this->user = User::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Store Retail Manager',
            'email' => 'retail.mgr.'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'role_id' => $this->role->id,
            'is_active' => true,
        ]);

        DB::table('user_stores')->insert([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
        ]);

        $category = \App\Models\Category::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'category_code' => 'CAT-'.substr(uniqid(), 0, 8),
            'category_name' => 'Fashion & Apparel',
            'is_active' => true,
        ]);

        $this->product = Product::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_code' => 'PRD-SHIRT-'.uniqid(),
            'product_name' => 'Kemeja Katun Premium Pria',
            'product_price' => '150000.00',
            'product_type' => 'variant_parent',
            'status' => 'ACTIVE',
            'has_variants' => true,
            'track_inventory' => true,
            'is_active' => true,
        ]);

        $this->variantA = ProductVariant::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $this->product->id,
            'variant_name' => 'Ukuran M / Biru Navy',
            'sku' => 'SKU-M-NAVY-'.uniqid(),
            'barcode' => '2000000000015',
            'price_override' => '150000.00',
            'standard_cost' => '75000.00',
            'is_active' => true,
        ]);

        $this->variantB = ProductVariant::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $this->product->id,
            'variant_name' => 'Ukuran L / Biru Navy',
            'sku' => 'SKU-L-NAVY-'.uniqid(),
            'barcode' => '2000000000022',
            'price_override' => '160000.00',
            'standard_cost' => '80000.00',
            'is_active' => true,
        ]);
    }

    public function test_shelf_label_manager_renders_and_generates_batch_labels(): void
    {
        Livewire::actingAs($this->user)
            ->test(ShelfLabelManager::class)
            ->assertStatus(200)
            ->assertSee('Retail Shelf Price Labels')
            ->set('selectedProductId', $this->product->id)
            ->set('quantity', 3)
            ->call('createLabel')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('shelf_labels', [
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'product_id' => $this->product->id,
            'status' => ShelfLabel::STATUS_PENDING,
        ]);

        $createdLabel = ShelfLabel::where('tenant_id', $this->tenant->id)->first();
        $this->assertNotNull($createdLabel);

        Livewire::actingAs($this->user)
            ->test(ShelfLabelManager::class)
            ->call('markBatchPrinted', $createdLabel->batch_number)
            ->assertHasNoErrors();

        $this->assertEquals(ShelfLabel::STATUS_PRINTED, $createdLabel->fresh()->status);
    }

    public function test_barcode_manager_validates_and_generates_ean13_barcodes(): void
    {
        $service = app(RetailBarcodeService::class);

        // 1. Verify EAN-13 checksum algorithm
        $this->assertTrue($service->validateEan13Checksum('2000000000015'));
        $this->assertFalse($service->validateEan13Checksum('2000000000019')); // Invalid check digit

        // 2. Test Livewire component validation
        Livewire::actingAs($this->user)
            ->test(BarcodeManager::class)
            ->assertStatus(200)
            ->set('barcodeToValidate', '2000000000015')
            ->call('validateBarcode')
            ->assertSet('isValidEan13', true);

        // 3. Test generate internal barcode
        $generated = $service->generateInternalBarcode();
        $this->assertTrue($service->validateEan13Checksum($generated));
        $this->assertStringStartsWith('20', $generated);
    }

    public function test_retail_exchange_manager_processes_size_exchange(): void
    {
        // 1. Create a paid retail sales order
        $order = Order::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'idempotency_key' => Str::uuid()->toString(),
            'order_code' => 'ORD-RTL-'.uniqid(),
            'order_date' => now()->toDateString(),
            'total_amount' => '150000.00',
            'net_amount' => '150000.00',
            'payment_status' => 'PAID',
        ]);
        $order->forceFill(['order_status' => OrderStatus::Paid])->save();

        $orderItem = OrderItem::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'product_variant_id' => $this->variantA->id,
            'qty' => 1,
            'unit_price' => '150000.00',
            'subtotal' => '150000.00',
            'cost_price' => '75000.00',
        ]);

        // Initialize stock balances
        DB::table('product_stock_balances')->updateOrInsert(
            [
                'tenant_id' => $this->tenant->id,
                'store_id' => $this->store->id,
                'product_id' => $this->product->id,
            ],
            [
                'quantity' => 10.0000,
                'updated_at' => now(),
            ]
        );

        $test = Livewire::actingAs($this->user)
            ->test(RetailExchangeManager::class)
            ->assertStatus(200)
            ->set('orderCode', $order->order_code)
            ->call('searchOrder')
            ->assertSet('order.id', $order->id)
            ->set('selectedOrderItemId', $orderItem->id)
            ->set('newVariantId', $this->variantB->id)
            ->set('exchangeQuantity', 1)
            ->call('processExchange')
            ->assertHasNoErrors();

        // Verify audit log for variant exchange was recorded
        $this->assertDatabaseHas('activity_logs', [
            'tenant_id' => $this->tenant->id,
            'action' => 'retail.variant_exchanged',
            'subject_id' => $order->id,
        ]);
    }

    public function test_retail_components_enforce_tenant_isolation(): void
    {
        // 1. Create shelf label in Tenant A while in Tenant A's context
        ShelfLabel::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'product_id' => $this->product->id,
            'old_price' => '150000.00',
            'new_price' => '150000.00',
            'status' => ShelfLabel::STATUS_PENDING,
            'batch_number' => 'BAT-SECRET-123',
        ]);

        // 2. Setup Tenant B and switch context
        $tenantB = Tenant::create([
            'name' => 'Competitor Retail Group '.uniqid(),
            'code' => 'RTL-B-'.strtoupper(Str::random(4)),
            'slug' => 'rtl-b-'.uniqid(),
            'status' => 'ACTIVE',
        ]);

        app(TenantContext::class)->setTenantId($tenantB->id);

        $storeB = Store::withoutGlobalScopes()->create([
            'tenant_id' => $tenantB->id,
            'name' => 'Store B',
            'code' => 'STR-B-'.substr(uniqid(), 0, 10),
            'is_active' => true,
        ]);
        app(TenantContext::class)->setStoreId($storeB->id);

        $roleB = Role::create([
            'tenant_id' => $tenantB->id,
            'name' => 'Owner',
            'permissions' => ['*'],
        ]);

        $userB = User::withoutGlobalScopes()->create([
            'tenant_id' => $tenantB->id,
            'name' => 'Manager B',
            'email' => 'mgr.b.'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'role_id' => $roleB->id,
            'is_active' => true,
        ]);

        DB::table('user_stores')->insert([
            'tenant_id' => $tenantB->id,
            'user_id' => $userB->id,
            'store_id' => $storeB->id,
        ]);

        // User B cannot see Tenant A's shelf labels
        Livewire::actingAs($userB)
            ->test(ShelfLabelManager::class)
            ->assertDontSee('BAT-SECRET-123');
    }
}
