<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Store;
use App\Models\StoreProductAssortment;
use App\Models\TaxClass;
use App\Models\Tenant;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Services\Context\TenantContext;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class Module3MvcAndEndToEndIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Store $storeA;

    private Store $storeB;

    private User $userA;

    private User $userB;

    private Shift $shiftA;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        Storage::fake('public');

        $context = app(TenantContext::class);
        $context->clear();

        // 1. Setup Tenant A
        $this->tenantA = Tenant::create([
            'name' => 'ZeroGap Tenant Alpha',
            'subdomain' => 'zerogap-alpha',
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);

        $context->setTenantId($this->tenantA->id);

        $this->storeA = Store::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Store Alpha Central',
            'is_active' => true,
        ]);

        $context->setStoreId($this->storeA->id);

        $roleOwnerA = Role::firstOrCreate([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Owner',
        ], [
            'role_code' => 'OWNER',
            'is_active' => true,
        ]);

        $this->userA = User::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'role_id' => $roleOwnerA->id,
            'email_verified_at' => now(),
        ]);

        $this->shiftA = Shift::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->userA->id,
            'starting_cash' => 500000,
            'start_time' => now(),
            'status' => 'open',
        ]);

        // 2. Setup Tenant B (Untuk pengujian Cross-Tenant Tampering IDOR)
        $this->tenantB = Tenant::create([
            'name' => 'ZeroGap Tenant Beta',
            'subdomain' => 'zerogap-beta',
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);

        $context->setTenantId($this->tenantB->id);

        $this->storeB = Store::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Store Beta Branch',
            'is_active' => true,
        ]);

        $roleOwnerB = Role::firstOrCreate([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Owner',
        ], [
            'role_code' => 'OWNER',
            'is_active' => true,
        ]);

        $this->userB = User::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'role_id' => $roleOwnerB->id,
            'email_verified_at' => now(),
        ]);

        // Restore context to Tenant A
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $this->actingAs($this->userA);
    }

    /**
     * Uji Siklus Penuh HTTP CRUD Produk & Rendering Blade Views.
     */
    public function test_product_full_http_lifecycle_and_blade_rendering(): void
    {
        $category = Category::factory()->create(['tenant_id' => $this->tenantA->id]);
        $brand = Brand::create(['tenant_id' => $this->tenantA->id, 'name' => 'Alpha Roasters', 'is_active' => true]);
        $uom = UnitOfMeasure::create(['tenant_id' => $this->tenantA->id, 'name' => 'Cup', 'code' => 'CUP', 'is_active' => true]);
        $taxClass = TaxClass::create(['tenant_id' => $this->tenantA->id, 'name' => 'PPN 11%', 'code' => 'PPN-11', 'rate_percent' => 11.00, 'is_active' => true]);

        // 1. GET products.index
        $response = $this->get(route('products.index'));
        $response->assertOk();
        $response->assertSee('Katalog Produk');
        $response->assertSee('Ekspor');
        $response->assertSee('Impor');

        // 2. GET products.create
        $response = $this->get(route('products.create'));
        $response->assertOk();
        $response->assertSee('Tambah Produk');
        $response->assertSee('Alpha Roasters');
        $response->assertSee('PPN 11%');

        // 3. POST products.store
        $photo = UploadedFile::fake()->image('cappuccino.jpg', 600, 600);
        $payload = [
            'product_name' => 'Caramel Cappuccino',
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'base_uom_id' => $uom->id,
            'tax_class_id' => $taxClass->id,
            'is_tax_inclusive' => 1,
            'product_code' => 'CAP-CAR-01',
            'barcode' => '899001122334',
            'product_type' => 'standard',
            'status' => 'active',
            'product_price' => 38000,
            'standard_cost' => 15000,
            'costing_method' => 'standard',
            'stock' => 100,
            'product_description' => 'Espresso dengan steamed milk dan saus caramel',
            'product_photo' => $photo,
        ];

        $postResponse = $this->post(route('products.store'), $payload);
        $postResponse->assertRedirect(route('products.index'));
        $postResponse->assertSessionHas('success');

        $product = Product::withoutGlobalScopes()->where('product_code', 'CAP-CAR-01')->first();
        $this->assertNotNull($product);
        $this->assertEquals('Caramel Cappuccino', $product->product_name);
        $this->assertEquals(38000, (float) $product->product_price);
        $this->assertEquals(15000, (float) $product->standard_cost);
        $this->assertEquals($taxClass->id, $product->tax_class_id);
        $this->assertTrue((bool) $product->is_tax_inclusive);

        // 4. GET products.edit
        $editResponse = $this->get(route('products.edit', $product->id));
        $editResponse->assertOk();
        $editResponse->assertSee('Ubah Produk');
        $editResponse->assertSee('Caramel Cappuccino');
        $editResponse->assertSee('Galeri Media Produk');
        $editResponse->assertSee('Riwayat Perubahan Biaya Pokok');

        // 5. PUT products.update
        $updatePayload = array_merge($payload, [
            'product_name' => 'Caramel Cappuccino Special',
            'product_price' => 42000,
            'standard_cost' => 17000,
            'cost_change_reason' => 'Kenaikan harga susu premium',
        ]);
        unset($updatePayload['product_photo']);

        $putResponse = $this->put(route('products.update', $product->id), $updatePayload);
        $putResponse->assertRedirect(route('products.index'));

        $product = Product::withoutGlobalScopes()->find($product->id);
        $this->assertEquals('Caramel Cappuccino Special', $product->product_name);
        $this->assertEquals(42000, (float) $product->product_price);
        $this->assertEquals(17000, (float) $product->standard_cost);

        // Verifikasi audit log biaya tercatat
        $this->assertDatabaseHas('product_cost_histories', [
            'product_id' => $product->id,
            'new_cost' => 17000,
            'reason' => 'Kenaikan harga susu premium',
        ]);

        // 6. DELETE products.destroy
        $deleteResponse = $this->delete(route('products.destroy', $product->id));
        $deleteResponse->assertRedirect(route('products.index'));
        $this->assertSoftDeleted('products', ['id' => $product->id]);
    }

    /**
     * Uji HTTP CRUD Kategori & Sub-Kategori.
     */
    public function test_category_full_http_lifecycle_and_blade_rendering(): void
    {
        // 1. GET categories.index
        $response = $this->get(route('categories.index'));
        $response->assertOk();
        $response->assertSee('Kategori & Sub-Kategori', false);

        // 2. POST categories.store (Parent category)
        $storeResponse = $this->post(route('categories.store'), [
            'category_name' => 'Minuman Panas',
            'category_code' => 'HOT-BEV',
            'is_active' => 1,
        ]);
        $storeResponse->assertRedirect(route('categories.index'));

        $parent = Category::withoutGlobalScopes()->where('category_code', 'HOT-BEV')->first();
        $this->assertNotNull($parent);

        // 3. POST categories.store (Sub-category dengan parent_id)
        $subResponse = $this->post(route('categories.store'), [
            'category_name' => 'Kopi Panas',
            'category_code' => 'HOT-COFFEE',
            'parent_id' => $parent->id,
            'is_active' => 1,
        ]);
        $subResponse->assertRedirect(route('categories.index'));

        $sub = Category::withoutGlobalScopes()->where('category_code', 'HOT-COFFEE')->first();
        $this->assertNotNull($sub);
        $this->assertEquals($parent->id, $sub->parent_id);

        // 4. GET categories.edit
        $editResponse = $this->get(route('categories.edit', $sub->id));
        $editResponse->assertOk();
        $editResponse->assertSee('Kopi Panas');

        // 5. PUT categories.update
        $updateResponse = $this->put(route('categories.update', $sub->id), [
            'category_name' => 'Hot Coffee & Latte',
            'category_code' => 'HOT-COFFEE',
            'parent_id' => $parent->id,
            'is_active' => 1,
        ]);
        $updateResponse->assertRedirect(route('categories.index'));
        $sub = Category::withoutGlobalScopes()->find($sub->id);
        $this->assertEquals('Hot Coffee & Latte', $sub->category_name);

        // 6. DELETE categories.destroy
        $deleteResponse = $this->delete(route('categories.destroy', $sub->id));
        $deleteResponse->assertRedirect(route('categories.index'));
        $this->assertSoftDeleted('categories', ['id' => $sub->id]);
    }

    /**
     * Uji HTTP CRUD Master Brand, Satuan Ukur (UoM), dan Kelas Pajak.
     */
    public function test_brands_uom_and_tax_classes_http_crud(): void
    {
        // 1. Brands CRUD
        $brandIndex = $this->get(route('brands.index'));
        $brandIndex->assertOk();
        $brandIndex->assertSee('Merek Produk');

        $brandStore = $this->post(route('brands.store'), [
            'code' => 'BR-TOR',
            'name' => 'Toraja Highlands',
            'description' => 'Biji kopi asli Tana Toraja',
            'is_active' => 1,
        ]);
        $brandStore->assertRedirect(route('brands.index'));
        $brand = Brand::withoutGlobalScopes()->where('name', 'Toraja Highlands')->first();
        $this->assertNotNull($brand);

        $brandUpdate = $this->put(route('brands.update', $brand->id), [
            'code' => 'BR-TOR',
            'name' => 'Toraja Highlands Specialty',
            'description' => 'Biji kopi grade 1 Tana Toraja',
            'is_active' => 1,
        ]);
        $brandUpdate->assertRedirect(route('brands.index'));
        $brand = Brand::withoutGlobalScopes()->find($brand->id);
        $this->assertEquals('Toraja Highlands Specialty', $brand->name);

        // 2. Unit of Measure CRUD
        $uomIndex = $this->get(route('units-of-measure.index'));
        $uomIndex->assertOk();
        $uomIndex->assertSee('Satuan Ukur');

        $uomStore = $this->post(route('units-of-measure.store'), [
            'name' => 'Kilogram',
            'code' => 'KG',
            'is_active' => 1,
        ]);
        $uomStore->assertRedirect(route('units-of-measure.index'));
        $uom = UnitOfMeasure::withoutGlobalScopes()->where('code', 'KG')->first();
        $this->assertNotNull($uom);

        // 3. Tax Classes CRUD
        $taxIndex = $this->get(route('tax-classes.index'));
        $taxIndex->assertOk();
        $taxIndex->assertSee('Kelas Pajak');

        $taxStore = $this->post(route('tax-classes.store'), [
            'name' => 'PB1 Restoran 10%',
            'code' => 'PB1-10',
            'rate' => 10.00,
            'is_active' => 1,
        ]);
        $taxStore->assertRedirect(route('tax-classes.index'));
        $tax = TaxClass::withoutGlobalScopes()->where('code', 'PB1-10')->first();
        $this->assertNotNull($tax);
        $this->assertEquals(10.00, (float) $tax->rate);
    }

    /**
     * Uji HTTP CRUD Price List & Item Syncing.
     */
    public function test_price_list_http_crud_and_blade_rendering(): void
    {
        $category = Category::factory()->create(['tenant_id' => $this->tenantA->id]);
        $product = Product::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'category_id' => $category->id,
            'product_price' => 50000,
        ]);

        // 1. GET price-lists.index
        $indexResponse = $this->get(route('price-lists.index'));
        $indexResponse->assertOk();
        $indexResponse->assertSee('Daftar Harga');

        // 2. GET price-lists.create
        $createResponse = $this->get(route('price-lists.create'));
        $createResponse->assertOk();
        $createResponse->assertSee('Buat Daftar Harga');

        // 3. POST price-lists.store
        $storeResponse = $this->post(route('price-lists.store'), [
            'name' => 'Harga Grosir Kantor',
            'code' => 'PL-GROSIR-01',
            'type' => 'wholesale',
            'priority' => 10,
            'currency_code' => 'IDR',
            'is_active' => 1,
            'items' => [
                [
                    'product_id' => $product->id,
                    'price' => 40000,
                    'min_quantity' => 5,
                ],
            ],
        ]);
        $storeResponse->assertRedirect(route('price-lists.index'));

        $priceList = PriceList::withoutGlobalScopes()->where('code', 'PL-GROSIR-01')->first();
        $this->assertNotNull($priceList);
        $this->assertCount(1, $priceList->items()->withoutGlobalScopes()->get());
        $this->assertEquals(40000, (float) $priceList->items()->withoutGlobalScopes()->first()->price);

        // 4. GET price-lists.edit
        $editResponse = $this->get(route('price-lists.edit', $priceList->id));
        $editResponse->assertOk();
        $editResponse->assertSee('Sunting Daftar Harga');

        // 5. PUT price-lists.update
        $updateResponse = $this->put(route('price-lists.update', $priceList->id), [
            'code' => 'PL-GROSIR-01',
            'name' => 'Harga Grosir VIP Kantor',
            'type' => 'wholesale',
            'priority' => 5,
            'currency_code' => 'IDR',
            'is_active' => 1,
            'items' => [
                [
                    'product_id' => $product->id,
                    'price' => 37500,
                    'min_quantity' => 10,
                ],
            ],
        ]);
        $updateResponse->assertRedirect(route('price-lists.index'));

        $priceList = PriceList::withoutGlobalScopes()->find($priceList->id);
        $this->assertEquals('Harga Grosir VIP Kantor', $priceList->name);
        $this->assertEquals(37500, (float) $priceList->items()->withoutGlobalScopes()->first()->price);

        // 6. DELETE price-lists.destroy
        $deleteResponse = $this->delete(route('price-lists.destroy', $priceList->id));
        $deleteResponse->assertRedirect(route('price-lists.index'));
        $this->assertSoftDeleted('price_lists', ['id' => $priceList->id]);
    }

    /**
     * Uji HTTP Pengaturan Asortimen & Override Harga Cabang.
     */
    public function test_store_product_assortment_http_flow(): void
    {
        $category = Category::factory()->create(['tenant_id' => $this->tenantA->id]);
        $product = Product::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'category_id' => $category->id,
            'product_price' => 30000,
        ]);

        // 1. GET assortments.index
        $indexResponse = $this->get(route('assortments.index', ['store_id' => $this->storeA->id]));
        $indexResponse->assertOk();
        $indexResponse->assertSee('Asortimen');

        // 2. POST assortments.update
        $postResponse = $this->post(route('assortments.update'), [
            'store_id' => $this->storeA->id,
            'product_id' => $product->id,
            'is_available' => 1,
            'store_price_override' => 35000,
        ]);
        $postResponse->assertRedirect(route('assortments.index', ['store_id' => $this->storeA->id]));

        $assortment = StoreProductAssortment::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantA->id)
            ->where('store_id', $this->storeA->id)
            ->where('product_id', $product->id)
            ->first();

        $this->assertNotNull($assortment);
        $this->assertTrue((bool) $assortment->is_available);
        $this->assertEquals(35000, (float) $assortment->store_price_override);
    }

    /**
     * Pengujian Keamanan HTTP Ketat Terhadap Serangan Cross-Tenant ID Tampering (IDOR).
     */
    public function test_cross_tenant_idor_tampering_is_rejected_on_all_endpoints(): void
    {
        // Entitas milik Tenant B
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantB->id);
        $categoryB = Category::factory()->create(['tenant_id' => $this->tenantB->id]);
        $brandB = Brand::create(['tenant_id' => $this->tenantB->id, 'name' => 'Beta Brand', 'is_active' => true]);
        $taxB = TaxClass::create(['tenant_id' => $this->tenantB->id, 'name' => 'Tax B', 'code' => 'TAX-B', 'rate_percent' => 5, 'is_active' => true]);
        $customerB = Customer::create(['tenant_id' => $this->tenantB->id, 'name' => 'Customer B', 'phone' => '0812999999']);
        $productB = Product::factory()->create(['tenant_id' => $this->tenantB->id, 'category_id' => $categoryB->id]);

        // Kembalikan konteks ke Tenant A
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        // Kategori sah milik Tenant A
        $categoryA = Category::factory()->create(['tenant_id' => $this->tenantA->id]);

        // 1. User A mengirim category_id milik Tenant B -> Ditolak 422
        $res1 = $this->post(route('products.store'), [
            'product_name' => 'Tampered Product 1',
            'category_id' => $categoryB->id,
            'product_price' => 10000,
        ]);
        $res1->assertSessionHasErrors('category_id');

        // 2. User A mengirim brand_id milik Tenant B -> Ditolak 422
        $res2 = $this->post(route('products.store'), [
            'product_name' => 'Tampered Product 2',
            'category_id' => $categoryA->id,
            'brand_id' => $brandB->id,
            'product_price' => 10000,
        ]);
        $res2->assertSessionHasErrors('brand_id');

        // 3. User A mengirim tax_class_id milik Tenant B -> Ditolak 422
        $res3 = $this->post(route('products.store'), [
            'product_name' => 'Tampered Product 3',
            'category_id' => $categoryA->id,
            'tax_class_id' => $taxB->id,
            'product_price' => 10000,
        ]);
        $res3->assertSessionHasErrors('tax_class_id');

        // 4. User A mencoba PriceList dengan store_id / customer_id milik Tenant B -> Ditolak 422
        $res4 = $this->post(route('price-lists.store'), [
            'name' => 'Tampered Price List',
            'type' => 'customer_specific',
            'priority' => 1,
            'customer_id' => $customerB->id,
        ]);
        $res4->assertSessionHasErrors('customer_id');

        $res5 = $this->post(route('price-lists.store'), [
            'name' => 'Tampered Branch Price List',
            'type' => 'branch_promotional',
            'priority' => 1,
            'store_id' => $this->storeB->id,
        ]);
        $res5->assertSessionHasErrors('store_id');

        // 5. User A mencoba mengakses / menyunting produk milik Tenant B secara langsung via URL -> 404 / 403
        $res6 = $this->get(route('products.edit', $productB->id));
        $this->assertContains($res6->status(), [403, 404]);

        $res7 = $this->put(route('products.update', $productB->id), [
            'product_name' => 'Tampered Product Name B',
            'category_id' => $categoryA->id,
            'product_price' => 15000,
        ]);
        $this->assertContains($res7->status(), [403, 404]);
    }

    /**
     * Pengujian End-to-End: Konfigurasi Harga Modul 3 (PriceList & Assortment)
     * tersambung deterministik ke Transaksi Kasir POS (TransactionService).
     */
    public function test_end_to_end_pricing_and_assortment_to_pos_checkout(): void
    {
        $category = Category::factory()->create(['tenant_id' => $this->tenantA->id]);
        $product = Product::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'category_id' => $category->id,
            'product_price' => 50000, // Harga dasar master
            'stock' => 100,
            'track_inventory' => true,
            'is_active' => true,
            'status' => 'active',
        ]);

        $customer = Customer::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'PT Mitra Sejahtera',
            'phone' => '081211112222',
        ]);

        // Skenario 1: Cabang A memiliki Store Price Override Rp 55.000
        StoreProductAssortment::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'product_id' => $product->id,
            'is_available' => true,
            'store_price_override' => 55000,
        ]);

        $txService = app(TransactionService::class);

        // Transaksi Walk-In tanpa customer: Harga harus mengambil Rp 55.000 (Store Override)
        $txWalkIn = $txService->createTransaction([
            'shift_id' => $this->shiftA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->userA->id,
            'customer_id' => null,
            'payment_method' => 'cash',
            'cash_received' => 100000,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                ],
            ],
        ], $this->userA->id);

        $orderItem1 = $txWalkIn->orderItems->first();
        $this->assertEquals(55000, (float) $orderItem1->order_price);

        // Skenario 2: Kontrak Harga Khusus Pelanggan Rp 45.000 (Prioritas Tertinggi Tier-1)
        $priceListCustomer = PriceList::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Kontrak Khusus PT Mitra',
            'type' => 'customer_specific',
            'customer_id' => $customer->id,
            'priority' => 1,
            'is_active' => true,
        ]);

        PriceListItem::create([
            'tenant_id' => $this->tenantA->id,
            'price_list_id' => $priceListCustomer->id,
            'product_id' => $product->id,
            'price' => 45000,
            'min_quantity' => 1,
        ]);

        // Transaksi dengan customer_id PT Mitra: Harga harus mengambil Rp 45.000 (Tier 1 Customer Contract)
        $txCustomer = $txService->createTransaction([
            'shift_id' => $this->shiftA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->userA->id,
            'customer_id' => $customer->id,
            'payment_method' => 'cash',
            'cash_received' => 100000,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 2,
                ],
            ],
        ], $this->userA->id);

        $orderItem2 = $txCustomer->orderItems->first();
        $this->assertEquals(45000, (float) $orderItem2->order_price);
        $this->assertEquals(90000, (float) $orderItem2->order_subtotal);

        // Skenario 3: Non-Aktifkan ketersediaan di cabang (Assortment is_available = false) -> Wajib Ditolak
        StoreProductAssortment::where('store_id', $this->storeA->id)
            ->where('product_id', $product->id)
            ->update(['is_available' => false]);

        $this->expectException(ValidationException::class);
        $txService->createTransaction([
            'shift_id' => $this->shiftA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->userA->id,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                ],
            ],
        ], $this->userA->id);
    }

    /**
     * Pengujian Produk Non-Stok (Service, Non-Inventory, Bundle):
     * Dapat di-checkout tanpa stok dan tidak memotong stock_balances / stock_movements.
     */
    public function test_non_stock_and_bundle_products_checkout_without_stock_movement(): void
    {
        $category = Category::factory()->create(['tenant_id' => $this->tenantA->id]);

        // 1. Produk Service (Jasa)
        $serviceProduct = Product::create([
            'tenant_id' => $this->tenantA->id,
            'category_id' => $category->id,
            'product_name' => 'Jasa Cuci Sepatu Deep Clean',
            'product_code' => 'SRV-01',
            'product_type' => 'service',
            'track_inventory' => false,
            'stock' => 0,
            'product_price' => 50000,
            'is_active' => true,
            'status' => 'active',
        ]);

        // 2. Produk Non-Inventory
        $nonInvProduct = Product::create([
            'tenant_id' => $this->tenantA->id,
            'category_id' => $category->id,
            'product_name' => 'Paper Bag Belanja',
            'product_code' => 'BAG-01',
            'product_type' => 'non_inventory',
            'track_inventory' => false,
            'stock' => 0,
            'product_price' => 2000,
            'is_active' => true,
            'status' => 'active',
        ]);

        $txService = app(TransactionService::class);

        $initialMovements = DB::table('stock_movements')->count();

        $order = $txService->createTransaction([
            'shift_id' => $this->shiftA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->userA->id,
            'payment_method' => 'cash',
            'cash_received' => 100000,
            'items' => [
                [
                    'product_id' => $serviceProduct->id,
                    'quantity' => 1,
                ],
                [
                    'product_id' => $nonInvProduct->id,
                    'quantity' => 2,
                ],
            ],
        ], $this->userA->id);

        $this->assertNotNull($order);
        $this->assertEquals(54000, (float) $order->subtotal_amount);

        // Tidak ada mutasi stok bertambah untuk item non-stock
        $this->assertEquals($initialMovements, DB::table('stock_movements')->count());
    }

    /**
     * Pengujian Endpoint HTTP Import Katalog & Bulk Update.
     */
    public function test_bulk_edit_and_import_http_routes(): void
    {
        $category = Category::factory()->create(['tenant_id' => $this->tenantA->id]);
        $p1 = Product::factory()->create(['tenant_id' => $this->tenantA->id, 'category_id' => $category->id, 'status' => 'draft']);
        $p2 = Product::factory()->create(['tenant_id' => $this->tenantA->id, 'category_id' => $category->id, 'status' => 'draft']);

        // 1. Bulk Update Status
        $bulkResponse = $this->post(route('products.bulk-update'), [
            'product_ids' => [$p1->id, $p2->id],
            'action' => 'update_status',
            'status' => 'active',
        ]);
        $bulkResponse->assertRedirect(route('products.index'));

        $p1->refresh();
        $p2->refresh();
        $this->assertEquals('active', $p1->status);
        $this->assertEquals('active', $p2->status);

        // 2. Import CSV Katalog
        $csvContent = "Product Name,Price,SKU,Status\nLatte Import,35000,LAT-IMP-01,active\n";

        $file = UploadedFile::fake()->createWithContent('products_import.csv', $csvContent);

        $importResponse = $this->post(route('products.import'), [
            'file' => $file,
        ]);
        $importResponse->assertRedirect(route('products.index'));

        app(TenantContext::class)->setTenantId($this->tenantA->id);
        $imported = Product::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantA->id)
            ->where('product_code', 'LAT-IMP-01')
            ->first();

        $this->assertNotNull($imported);
        $this->assertEquals('Latte Import', $imported->product_name);
        $this->assertEquals(35000, (float) $imported->product_price);
    }
}
