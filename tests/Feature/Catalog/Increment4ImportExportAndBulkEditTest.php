<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Exports\CategoriesExport;
use App\Exports\ProductsExport;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreProductAssortment;
use App\Models\TaxClass;
use App\Models\Tenant;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Services\CatalogBulkEditService;
use App\Services\Context\TenantContext;
use App\Services\ProductImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class Increment4ImportExportAndBulkEditTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Store $storeA;

    private User $userA;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $context = app(TenantContext::class);
        $context->clear();

        // Setup Tenant A
        $this->tenantA = Tenant::create([
            'name' => 'Inc4 Tenant Alpha',
            'subdomain' => 'inc4-alpha',
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);

        $context->setTenantId($this->tenantA->id);

        $this->storeA = Store::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Store Alpha 1',
            'is_active' => true,
        ]);

        $context->setStoreId($this->storeA->id);

        $role = Role::firstOrCreate([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Owner',
        ], [
            'role_code' => 'OWNER',
            'is_active' => true,
        ]);

        $this->userA = User::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'role_id' => $role->id,
        ]);

        // Setup Tenant B (untuk pengujian cross-tenant rejection)
        $this->tenantB = Tenant::create([
            'name' => 'Inc4 Tenant Beta',
            'subdomain' => 'inc4-beta',
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);

        $this->actingAs($this->userA);
    }

    public function test_products_export_includes_module_3_columns_and_sanitizes_formulas(): void
    {
        $category = Category::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'category_name' => 'Beverages',
        ]);

        $brand = Brand::create([
            'tenant_id' => $this->tenantA->id,
            'code' => 'BR-TEST',
            'name' => 'Test Brand',
        ]);

        $uom = UnitOfMeasure::create([
            'tenant_id' => $this->tenantA->id,
            'code' => 'CUP',
            'name' => 'Cup',
            'precision_scale' => 0,
        ]);

        $taxClass = TaxClass::create([
            'tenant_id' => $this->tenantA->id,
            'code' => 'PPN-11',
            'name' => 'PPN 11%',
            'rate' => 11.0,
        ]);

        // Buat produk dengan input yang berpotensi formula injection
        $maliciousProduct = Product::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'base_uom_id' => $uom->id,
            'tax_class_id' => $taxClass->id,
            'product_code' => '=CMD|calc.exe',
            'product_name' => '+Formula Name',
            'barcode' => '-BARCODE123',
            'product_price' => 25000,
            'standard_cost' => 12000,
            'is_tax_inclusive' => true,
            'product_type' => 'standard',
            'status' => 'active',
        ]);

        $export = new ProductsExport(Product::query()->where('id', $maliciousProduct->id));
        $mapped = $export->map($maliciousProduct);

        // Verifikasi sanitasi formula (di-prefix tanda kutip satu)
        $this->assertSame("'=CMD|calc.exe", $mapped[1]); // SKU
        $this->assertSame("'+Formula Name", $mapped[2]); // Product Name
        $this->assertSame("'-BARCODE123", $mapped[3]);   // Barcode

        // Verifikasi kelengkapan kolom Modul 3
        $this->assertSame('Beverages', $mapped[4]);
        $this->assertSame('Test Brand', $mapped[5]);
        $this->assertSame('Cup', $mapped[6]);
        $this->assertSame('standard', $mapped[7]);
        $this->assertEquals(25000, $mapped[8]);
        $this->assertEquals(12000, $mapped[10]);
        $this->assertSame('PPN 11%', $mapped[11]);
        $this->assertSame('Yes', $mapped[12]); // Tax Inclusive
        $this->assertSame('Active', $mapped[15]);
    }

    public function test_categories_export_includes_parent_and_sanitizes_formulas(): void
    {
        $parent = Category::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'category_name' => 'Parent Foods',
        ]);

        $child = Category::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'parent_id' => $parent->id,
            'category_name' => '@Snacks',
            'sort_order' => 5,
            'is_active' => true,
        ]);

        $export = new CategoriesExport(Category::query()->where('id', $child->id));
        $mapped = $export->map($child);

        $this->assertSame("'@Snacks", $mapped[1]); // Category Name disanitasi
        $this->assertSame('Parent Foods', $mapped[2]); // Parent Name
        $this->assertSame(5, $mapped[3]); // Sort Order
        $this->assertSame('Active', $mapped[4]); // Status
    }

    public function test_product_import_service_validates_and_imports_valid_rows(): void
    {
        $category = Category::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'category_name' => 'Food Menu',
        ]);

        $brand = Brand::create([
            'tenant_id' => $this->tenantA->id,
            'code' => 'BR-ALPHA',
            'name' => 'Alpha Brand',
        ]);

        $importService = app(ProductImportService::class);

        $rows = [
            [
                'product_name' => 'Nasi Goreng Spesial',
                'sku' => 'NGS-001',
                'barcode' => '899123456001',
                'category' => 'Food Menu',
                'brand' => 'Alpha Brand',
                'price' => '35000',
                'cost' => '15000',
                'product_type' => 'physical',
                'stock' => '20',
                'status' => 'active',
            ],
            [
                'product_name' => 'Delivery Service VIP',
                'sku' => 'SRV-001',
                'price' => '10000',
                'product_type' => 'service',
                'status' => 'active',
            ],
        ];

        $result = $importService->importRows($rows, $this->tenantA->id);

        $this->assertSame(2, $result['total_rows']);
        $this->assertSame(2, $result['imported_count']);
        $this->assertSame(0, $result['failed_count']);

        // Verifikasi produk tersimpan di DB
        $p1 = Product::where('tenant_id', $this->tenantA->id)->where('product_code', 'NGS-001')->first();
        $this->assertNotNull($p1);
        $this->assertEquals(35000, (float) $p1->product_price);
        $this->assertEquals($category->id, $p1->category_id);
        $this->assertEquals($brand->id, $p1->brand_id);
        $this->assertTrue($p1->track_inventory);
        $this->assertEquals(20, $p1->stock);

        // Verifikasi produk service otomatis track_inventory = false dan stock = 0
        $p2 = Product::where('tenant_id', $this->tenantA->id)->where('product_code', 'SRV-001')->first();
        $this->assertNotNull($p2);
        $this->assertFalse($p2->track_inventory);
        $this->assertEquals(0, $p2->stock);
    }

    public function test_product_import_service_rejects_cross_tenant_foreign_keys(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantB->id);

        // Kategori milik Tenant B
        $categoryTenantB = Category::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'category_name' => 'Foreign Category',
        ]);

        $context->setTenantId($this->tenantA->id);

        $importService = app(ProductImportService::class);

        $rows = [
            [
                'product_name' => 'Unauthorized Item',
                'price' => '20000',
                'category_id' => $categoryTenantB->id, // Mencoba mereferensikan kategori tenant lain
            ],
        ];

        $result = $importService->importRows($rows, $this->tenantA->id);

        $this->assertSame(1, $result['total_rows']);
        $this->assertSame(0, $result['imported_count']);
        $this->assertSame(1, $result['failed_count']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('milik tenant lain', $result['errors'][0]['errors'][0]);
    }

    public function test_product_import_service_detects_duplicate_skus_and_invalid_prices(): void
    {
        // Sudah ada produk di tenant A dengan SKU EX-001
        Product::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'product_code' => 'EX-001',
            'product_name' => 'Existing Product',
        ]);

        $importService = app(ProductImportService::class);

        $rows = [
            [
                // SKU duplikat dari database
                'product_name' => 'Duplicated Item',
                'sku' => 'EX-001',
                'price' => '15000',
            ],
            [
                // Harga negatif
                'product_name' => 'Negative Price Item',
                'sku' => 'NEG-001',
                'price' => '-5000',
            ],
            [
                // Nama kosong
                'product_name' => '',
                'price' => '10000',
            ],
            [
                // Item valid
                'product_name' => 'Valid Item',
                'sku' => 'VAL-001',
                'price' => '25000',
            ],
        ];

        $result = $importService->importRows($rows, $this->tenantA->id);

        $this->assertSame(4, $result['total_rows']);
        $this->assertSame(1, $result['imported_count']); // Hanya 1 yang berhasil
        $this->assertSame(3, $result['failed_count']);  // 3 gagal

        $this->assertCount(3, $result['errors']);
    }

    public function test_product_import_service_strict_mode_rolls_back_on_error(): void
    {
        $importService = app(ProductImportService::class);

        $rows = [
            [
                'product_name' => 'Good Item 1',
                'sku' => 'GOOD-001',
                'price' => '15000',
            ],
            [
                'product_name' => 'Bad Item 2',
                'sku' => 'BAD-002',
                'price' => '-1000', // Error!
            ],
        ];

        // Mode strict wajib melempar ValidationException dan tidak ada produk tersimpan
        try {
            $importService->importRows($rows, $this->tenantA->id, strictMode: true);
            $this->fail('Seharusnya melempar ValidationException saat baris kedua tidak valid dalam mode strict.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('import', $e->errors());
        }

        $this->assertDatabaseMissing('products', ['product_code' => 'GOOD-001']);
    }

    public function test_catalog_bulk_edit_service_updates_status_atomically(): void
    {
        $bulkService = app(CatalogBulkEditService::class);

        $p1 = Product::factory()->create(['tenant_id' => $this->tenantA->id, 'status' => 'active', 'is_active' => true]);
        $p2 = Product::factory()->create(['tenant_id' => $this->tenantA->id, 'status' => 'active', 'is_active' => true]);

        $updated = $bulkService->bulkUpdateStatus([$p1->id, $p2->id], 'inactive', $this->tenantA->id);

        $this->assertSame(2, $updated);

        $this->assertSame('inactive', $p1->fresh()->status);
        $this->assertFalse($p1->fresh()->is_active);
        $this->assertSame('inactive', $p2->fresh()->status);
        $this->assertFalse($p2->fresh()->is_active);
    }

    public function test_catalog_bulk_edit_service_updates_category_atomically(): void
    {
        $bulkService = app(CatalogBulkEditService::class);

        $oldCat = Category::factory()->create(['tenant_id' => $this->tenantA->id]);
        $newCat = Category::factory()->create(['tenant_id' => $this->tenantA->id]);

        $p1 = Product::factory()->create(['tenant_id' => $this->tenantA->id, 'category_id' => $oldCat->id]);
        $p2 = Product::factory()->create(['tenant_id' => $this->tenantA->id, 'category_id' => $oldCat->id]);

        $updated = $bulkService->bulkUpdateCategory([$p1->id, $p2->id], $newCat->id, $this->tenantA->id);

        $this->assertSame(2, $updated);
        $this->assertSame($newCat->id, $p1->fresh()->category_id);
        $this->assertSame($newCat->id, $p2->fresh()->category_id);
    }

    public function test_catalog_bulk_edit_service_updates_prices_with_modes(): void
    {
        $bulkService = app(CatalogBulkEditService::class);

        $p1 = Product::factory()->create(['tenant_id' => $this->tenantA->id, 'product_price' => 20000]);
        $p2 = Product::factory()->create(['tenant_id' => $this->tenantA->id, 'product_price' => 50000]);

        // 1. Mode Percentage (+10%)
        $bulkService->bulkUpdatePrice([$p1->id, $p2->id], 10, 'percentage', $this->tenantA->id);
        $this->assertEquals(22000, (float) $p1->fresh()->product_price);
        $this->assertEquals(55000, (float) $p2->fresh()->product_price);

        // 2. Mode Delta (+5000)
        $bulkService->bulkUpdatePrice([$p1->id], 5000, 'delta', $this->tenantA->id);
        $this->assertEquals(27000, (float) $p1->fresh()->product_price);

        // 3. Mode Fixed (Set ke 30000)
        $bulkService->bulkUpdatePrice([$p2->id], 30000, 'fixed', $this->tenantA->id);
        $this->assertEquals(30000, (float) $p2->fresh()->product_price);
    }

    public function test_catalog_bulk_edit_service_updates_store_assortment(): void
    {
        $bulkService = app(CatalogBulkEditService::class);

        $p1 = Product::factory()->create(['tenant_id' => $this->tenantA->id, 'product_price' => 20000]);
        $p2 = Product::factory()->create(['tenant_id' => $this->tenantA->id, 'product_price' => 30000]);

        $updated = $bulkService->bulkUpdateStoreAssortment(
            [$p1->id, $p2->id],
            $this->storeA->id,
            ['is_available' => false, 'store_price_override' => 25000],
            $this->tenantA->id
        );

        $this->assertSame(2, $updated);

        $a1 = StoreProductAssortment::where('tenant_id', $this->tenantA->id)
            ->where('store_id', $this->storeA->id)
            ->where('product_id', $p1->id)
            ->first();

        $this->assertNotNull($a1);
        $this->assertFalse($a1->is_available);
        $this->assertEquals(25000, (float) $a1->store_price_override);
    }

    public function test_product_controller_import_and_bulk_update_endpoints(): void
    {
        // 1. Test POST /products/import via HTTP
        $csvContent = "Product Name,Price,Status\nItem Import HTTP,18000,active\n";
        $file = UploadedFile::fake()->createWithContent('products.csv', $csvContent);

        $response = $this->post(route('products.import'), [
            'file' => $file,
        ]);

        $response->assertRedirect(route('products.index'));
        $this->assertDatabaseHas('products', [
            'tenant_id' => $this->tenantA->id,
            'product_name' => 'Item Import HTTP',
        ]);

        // 2. Test POST /products/bulk-update via HTTP
        app(TenantContext::class)->setTenantId($this->tenantA->id);
        $p = Product::where('tenant_id', $this->tenantA->id)->where('product_name', 'Item Import HTTP')->first();
        $this->assertNotNull($p);

        $response = $this->post(route('products.bulk-update'), [
            'product_ids' => [$p->id],
            'action' => 'update_status',
            'status' => 'discontinued',
        ]);

        $response->assertRedirect(route('products.index'));
        $this->assertSame('discontinued', Product::withoutGlobalScopes()->find($p->id)->status);
    }
}
