<?php

declare(strict_types=1);

namespace Tests\Feature\Kasir;

use App\Livewire\Kasir\CreateOrder;
use App\Models\Category;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\ProductUnitConversion;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class PosProductConfiguratorModalTest extends TestCase
{
    use DatabaseTransactions;

    protected Tenant $tenant;

    protected Store $store;

    protected User $cashierUser;

    protected Category $category;

    protected Shift $shift;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        app(TenantContext::class)->clear();

        $this->tenant = Tenant::create([
            'name' => 'POS Configurator Test Tenant',
            'slug' => 'pos-config-tenant-'.uniqid(),
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'POS Config Test Store',
            'code' => substr('CF'.uniqid(), 0, 10),
            'is_active' => true,
        ]);

        app(TenantContext::class)->setStoreId($this->store->id);

        $cashierRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Kasir',
            'permissions' => ['*'],
        ]);

        $this->cashierUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $cashierRole->id,
        ]);

        DB::table('user_stores')->insert([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->cashierUser->id,
            'store_id' => $this->store->id,
        ]);

        $this->shift = Shift::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->cashierUser->id,
            'status' => 'open',
            'opened_at' => now(),
            'starting_cash' => 500000,
        ]);

        $this->category = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Coffee & Beverages',
        ]);
    }

    /**
     * Requirement 1: Pemilihan modifier dengan is_default = true otomatis terpilih saat modal dibuka.
     */
    public function test_default_modifiers_are_auto_selected_when_configurator_modal_opens(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_code' => 'ESP-001',
            'product_name' => 'Espresso Single Shot',
            'product_price' => 22000,
            'stock' => 100,
            'is_active' => true,
            'status' => 'active',
            'track_inventory' => false,
        ]);

        $group = ModifierGroup::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Sugar Level',
            'selection_type' => 'single',
            'is_required' => false,
        ]);

        $modNormal = Modifier::create([
            'tenant_id' => $this->tenant->id,
            'modifier_group_id' => $group->id,
            'name' => 'Normal Sugar',
            'extra_price' => 0,
            'is_default' => true,
            'is_active' => true,
        ]);

        $modLess = Modifier::create([
            'tenant_id' => $this->tenant->id,
            'modifier_group_id' => $group->id,
            'name' => 'Less Sugar',
            'extra_price' => 0,
            'is_default' => false,
            'is_active' => true,
        ]);

        $product->modifierGroups()->attach($group->id, ['tenant_id' => $this->tenant->id]);

        Livewire::actingAs($this->cashierUser)
            ->test(CreateOrder::class)
            ->call('openModifierPicker', $product->id)
            ->assertSet('selectingProductId', $product->id)
            ->assertSet('pendingQty', 1)
            ->assertSet('pendingModifierIds', [$modNormal->id]);
    }

    /**
     * Requirement 2: Pemilihan ProductVariant + Modifier menghitung subtotal baris dengan presisi di keranjang.
     */
    public function test_product_variant_and_modifier_calculates_line_subtotal_precisely_in_cart(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_code' => 'COF-VAR',
            'product_name' => 'Signature Latte',
            'product_price' => 20000,
            'stock' => 50,
            'is_active' => true,
            'status' => 'active',
            'track_inventory' => false,
            'has_variants' => true,
        ]);

        $variantLarge = ProductVariant::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $product->id,
            'variant_name' => 'Large (500ml)',
            'price_override' => 25000,
            'is_active' => true,
        ]);

        $group = ModifierGroup::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Milk Option',
            'selection_type' => 'single',
            'is_required' => false,
        ]);

        $modOat = Modifier::create([
            'tenant_id' => $this->tenant->id,
            'modifier_group_id' => $group->id,
            'name' => 'Oat Milk',
            'extra_price' => 5000,
            'is_default' => false,
            'is_active' => true,
        ]);

        $product->modifierGroups()->attach($group->id, ['tenant_id' => $this->tenant->id]);

        // (Harga Varian 25.000 + Modifier 5.000) * Qty 2 = 60.000
        Livewire::actingAs($this->cashierUser)
            ->test(CreateOrder::class)
            ->call('openModifierPicker', $product->id)
            ->call('setPendingVariant', $variantLarge->id)
            ->set('pendingModifierIds', [$modOat->id])
            ->set('pendingQty', 2)
            ->call('confirmAddToCart')
            ->assertHasNoErrors()
            ->assertSet('cart.0.product_id', $product->id)
            ->assertSet('cart.0.product_variant_id', $variantLarge->id)
            ->assertSet('cart.0.qty', 2)
            ->assertSet('cart.0.modifier_ids', [$modOat->id]);

        // Subtotal keranjang harus tepat 60.000
        $component = Livewire::actingAs($this->cashierUser)->test(CreateOrder::class);
        $component->call('openModifierPicker', $product->id)
            ->call('setPendingVariant', $variantLarge->id)
            ->set('pendingModifierIds', [$modOat->id])
            ->set('pendingQty', 2)
            ->call('confirmAddToCart');

        /** @var CreateOrder $instance */
        $instance = $component->instance();
        $this->assertEquals(60000, $instance->subtotal());
    }

    /**
     * Requirement 3a: Validasi is_required pada modifier_groups bekerja dengan benar.
     */
    public function test_required_modifier_group_validation_prevents_adding_to_cart_when_missing(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_code' => 'COF-REQ',
            'product_name' => 'Americano',
            'product_price' => 18000,
            'stock' => 50,
            'is_active' => true,
            'status' => 'active',
            'track_inventory' => false,
        ]);

        $group = ModifierGroup::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Temperature Option',
            'selection_type' => 'single',
            'is_required' => true,
        ]);

        $modHot = Modifier::create([
            'tenant_id' => $this->tenant->id,
            'modifier_group_id' => $group->id,
            'name' => 'Hot',
            'extra_price' => 0,
            'is_default' => false,
            'is_active' => true,
        ]);

        $product->modifierGroups()->attach($group->id, ['tenant_id' => $this->tenant->id]);

        Livewire::actingAs($this->cashierUser)
            ->test(CreateOrder::class)
            ->call('openModifierPicker', $product->id)
            ->set('pendingModifierIds', [])
            ->call('confirmAddToCart')
            ->assertHasErrors(['cart'])
            ->assertSet('cart', []);
    }

    /**
     * Requirement 3b: Validasi max_select pada modifier_groups bekerja dengan benar.
     */
    public function test_max_select_validation_prevents_selecting_more_than_allowed_modifiers(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_code' => 'DES-MAX',
            'product_name' => 'Waffle Delight',
            'product_price' => 30000,
            'stock' => 50,
            'is_active' => true,
            'status' => 'active',
            'track_inventory' => false,
        ]);

        $group = ModifierGroup::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Free Toppings',
            'selection_type' => 'multiple',
            'is_required' => false,
            'max_select' => 2,
        ]);

        $mod1 = Modifier::create([
            'tenant_id' => $this->tenant->id,
            'modifier_group_id' => $group->id,
            'name' => 'Almond',
            'extra_price' => 0,
            'is_active' => true,
        ]);

        $mod2 = Modifier::create([
            'tenant_id' => $this->tenant->id,
            'modifier_group_id' => $group->id,
            'name' => 'Choco Chips',
            'extra_price' => 0,
            'is_active' => true,
        ]);

        $mod3 = Modifier::create([
            'tenant_id' => $this->tenant->id,
            'modifier_group_id' => $group->id,
            'name' => 'Caramel',
            'extra_price' => 0,
            'is_active' => true,
        ]);

        $product->modifierGroups()->attach($group->id, ['tenant_id' => $this->tenant->id]);

        // Mencoba menambahkan 3 opsi melebihi batas max_select = 2
        Livewire::actingAs($this->cashierUser)
            ->test(CreateOrder::class)
            ->call('openModifierPicker', $product->id)
            ->set('pendingModifierIds', [$mod1->id, $mod2->id, $mod3->id])
            ->call('confirmAddToCart')
            ->assertHasErrors(['cart'])
            ->assertSet('cart', []);
    }

    /**
     * Validasi varian wajib dipilih jika produk memiliki varian aktif.
     */
    public function test_variant_is_required_when_product_has_active_variants(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_code' => 'TSHIRT-01',
            'product_name' => 'Plain T-Shirt',
            'product_price' => 75000,
            'stock' => 30,
            'is_active' => true,
            'status' => 'active',
            'track_inventory' => false,
            'has_variants' => true,
        ]);

        ProductVariant::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $product->id,
            'variant_name' => 'Size M - Black',
            'is_active' => true,
        ]);

        // Modal dibuka, belum pilih varian
        Livewire::actingAs($this->cashierUser)
            ->test(CreateOrder::class)
            ->call('openModifierPicker', $product->id)
            ->set('pendingVariantId', null)
            ->call('confirmAddToCart')
            ->assertHasErrors(['cart'])
            ->assertSet('cart', []);
    }

    /**
     * Fast-Path: Produk sederhana tanpa varian, modifiers, atau multi-UOM langsung masuk keranjang.
     */
    public function test_fast_path_directly_adds_simple_product_to_cart_without_modal(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_code' => 'MIN-001',
            'product_name' => 'Mineral Water 600ml',
            'product_price' => 5000,
            'stock' => 100,
            'is_active' => true,
            'status' => 'active',
            'track_inventory' => false,
            'has_variants' => false,
        ]);

        Livewire::actingAs($this->cashierUser)
            ->test(CreateOrder::class)
            ->call('addToCartDirectly', $product->id)
            ->assertSet('selectingProductId', null)
            ->assertSet('cart.0.product_id', $product->id)
            ->assertSet('cart.0.qty', 1);
    }

    /**
     * Satuan Jual Multi-UOM menyesuaikan harga efektif di keranjang.
     */
    public function test_multi_uom_conversion_adjusts_effective_price_in_cart(): void
    {
        $baseUom = UnitOfMeasure::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Piece',
            'code' => 'PCS',
            'is_active' => true,
        ]);

        $boxUom = UnitOfMeasure::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Box 12 Pcs',
            'code' => 'BOX',
            'is_active' => true,
        ]);

        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'base_uom_id' => $baseUom->id,
            'product_code' => 'PEN-BOX',
            'product_name' => 'Gel Pen 0.5mm',
            'product_price' => 5000,
            'stock' => 200,
            'is_active' => true,
            'status' => 'active',
            'track_inventory' => false,
            'has_variants' => false,
        ]);

        ProductUnitConversion::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $product->id,
            'from_uom_id' => $boxUom->id,
            'to_uom_id' => $baseUom->id,
            'conversion_factor' => 12.000000,
        ]);

        // Harga per Box = 5.000 * 12 = 60.000. Qty 1 Box = 60.000
        Livewire::actingAs($this->cashierUser)
            ->test(CreateOrder::class)
            ->call('openModifierPicker', $product->id)
            ->call('setPendingUom', $boxUom->id)
            ->set('pendingQty', 1)
            ->call('confirmAddToCart')
            ->assertHasNoErrors()
            ->assertSet('cart.0.product_id', $product->id)
            ->assertSet('cart.0.uom_id', $boxUom->id);

        $component = Livewire::actingAs($this->cashierUser)->test(CreateOrder::class);
        $component->call('openModifierPicker', $product->id)
            ->call('setPendingUom', $boxUom->id)
            ->set('pendingQty', 1)
            ->call('confirmAddToCart');

        /** @var CreateOrder $instance */
        $instance = $component->instance();
        $this->assertEquals(60000, $instance->subtotal());
    }

    /**
     * Verifikasi submitOrder() dengan Multi-UOM berhasil tanpa mismatch pembayaran
     * dan mempertahankan kalkulasi harga konversi UOM.
     */
    public function test_order_submission_with_multi_uom_preserves_custom_price_and_succeeds(): void
    {
        $baseUom = UnitOfMeasure::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Piece',
            'code' => 'PCS',
            'is_active' => true,
        ]);

        $boxUom = UnitOfMeasure::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Box 12 Pcs',
            'code' => 'BOX',
            'is_active' => true,
        ]);

        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'base_uom_id' => $baseUom->id,
            'product_code' => 'PEN-SUBMIT',
            'product_name' => 'Gel Pen Box Submit Test',
            'product_price' => 5000,
            'stock' => 200,
            'is_active' => true,
            'status' => 'active',
            'track_inventory' => false,
            'has_variants' => false,
        ]);

        ProductUnitConversion::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $product->id,
            'from_uom_id' => $boxUom->id,
            'to_uom_id' => $baseUom->id,
            'conversion_factor' => 12.000000,
        ]);

        $component = Livewire::actingAs($this->cashierUser)->test(CreateOrder::class);
        $component->call('openModifierPicker', $product->id)
            ->call('setPendingUom', $boxUom->id)
            ->call('setPendingQty', 1)
            ->call('confirmAddToCart');

        /** @var CreateOrder $instance */
        $instance = $component->instance();
        $this->assertEquals(60000, $instance->subtotal());
        $totalDue = $instance->totalAmount();

        $component->set('orderType', 'takeaway')
            ->call('submitOrder', [
                ['method' => 'cash', 'amount' => $totalDue],
            ])
            ->assertHasNoErrors();

        // Pesanan berhasil tercatat di database dengan subtotal 60.000 dan status paid
        $this->assertDatabaseHas('orders', [
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'subtotal_amount' => 60000,
            'order_amount' => $totalDue,
            'order_status' => 'paid',
        ]);
    }

    /**
     * Verifikasi pemotongan stok Multi-UOM via UnitConversionService:
     * Jual 2 Box (12 pcs/box) memotong 24 pcs dari inventaris.
     */
    public function test_multi_uom_deducts_inventory_with_conversion_service(): void
    {
        $baseUom = UnitOfMeasure::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Piece',
            'code' => 'PCS',
            'is_active' => true,
        ]);

        $boxUom = UnitOfMeasure::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Box 12 Pcs',
            'code' => 'BOX',
            'is_active' => true,
        ]);

        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'base_uom_id' => $baseUom->id,
            'product_code' => 'STK-UOM-01',
            'product_name' => 'Barang Stok Multi-UOM',
            'product_price' => 10000,
            'stock' => 100,
            'is_active' => true,
            'status' => 'active',
            'track_inventory' => true,
            'has_variants' => false,
        ]);

        ProductUnitConversion::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $product->id,
            'from_uom_id' => $boxUom->id,
            'to_uom_id' => $baseUom->id,
            'conversion_factor' => 12.000000,
        ]);

        // Inisialisasi saldo stok awal di product_stock_balances
        DB::table('product_stock_balances')->updateOrInsert(
            [
                'tenant_id' => $this->tenant->id,
                'store_id' => $this->store->id,
                'product_id' => $product->id,
            ],
            [
                'quantity' => 100,
                'reserved_quantity' => 0,
                'baseline_quantity' => 100,
                'average_unit_cost' => 5000,
                'last_unit_cost' => 5000,
                'updated_at' => now(),
            ]
        );

        // Jual 2 Box = 2 * 12 = 24 Pcs. Harga = 10.000 * 12 * 2 = 240.000
        $component = Livewire::actingAs($this->cashierUser)->test(CreateOrder::class);
        $component->call('openModifierPicker', $product->id)
            ->call('setPendingUom', $boxUom->id)
            ->call('setPendingQty', 2)
            ->call('confirmAddToCart');

        /** @var CreateOrder $instance */
        $instance = $component->instance();
        $this->assertEquals(240000, $instance->subtotal());
        $totalDue = $instance->totalAmount();

        $component->set('orderType', 'takeaway')
            ->call('submitOrder', [
                ['method' => 'cash', 'amount' => $totalDue],
            ])
            ->assertHasNoErrors();

        // Saldo stok harus berkurang 24 pcs (sisa 76 pcs), BUKAN hanya 2 pcs
        $remainingStock = DB::table('product_stock_balances')
            ->where('tenant_id', $this->tenant->id)
            ->where('store_id', $this->store->id)
            ->where('product_id', $product->id)
            ->value('quantity');

        $this->assertEquals(76, (float) $remainingStock);
    }

    /**
     * Verifikasi scan barcode varian pada produk ber-modifier wajib:
     * Modal configurator terbuka dengan varian yang discan langsung terpilih.
     */
    public function test_scan_barcode_variant_preselects_variant_when_modifiers_required(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_code' => 'COF-SCAN-VAR',
            'product_name' => 'Cappuccino Scan Test',
            'product_price' => 25000,
            'stock' => 50,
            'is_active' => true,
            'status' => 'active',
            'track_inventory' => false,
            'has_variants' => true,
        ]);

        $variant = ProductVariant::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $product->id,
            'variant_name' => 'Regular Cup',
            'barcode' => '899887766554',
            'sku' => 'CAP-REG',
            'price_override' => 25000,
            'is_active' => true,
        ]);

        $group = ModifierGroup::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Serving Temp',
            'selection_type' => 'single',
            'is_required' => true,
        ]);

        $modIce = Modifier::create([
            'tenant_id' => $this->tenant->id,
            'modifier_group_id' => $group->id,
            'name' => 'Iced',
            'extra_price' => 2000,
            'is_active' => true,
            'is_default' => true,
        ]);

        $product->modifierGroups()->attach($group->id, ['tenant_id' => $this->tenant->id]);

        Livewire::actingAs($this->cashierUser)
            ->test(CreateOrder::class)
            ->call('scanBarcode', '899887766554')
            ->assertSet('selectingProductId', $product->id)
            ->assertSet('pendingVariantId', $variant->id)
            ->assertSet('pendingModifierIds', [$modIce->id]);
    }
}
