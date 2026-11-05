<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Tenant;
use App\Modules\Catalog\Services\ProductCostHistoryService;
use App\Modules\Catalog\Services\ProductVariantMatrixService;
use App\Modules\Catalog\Services\StoreAssortmentService;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductAttribute;
use App\Modules\Products\Models\ProductAttributeValue;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Settings\Models\Store;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class Wave2CatalogTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;

    private Store $storeA;

    private Store $storeB;

    private Category $category;

    protected function afterSubclassSetUp(): void
    {
        $this->tenant = Tenant::create([
            'name' => 'Wave 2 Catalog Tenant',
            'status' => 'active',
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->storeA = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Branch Alpha',
            'code' => 'BR-A',
        ]);

        $this->storeB = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Branch Beta',
            'code' => 'BR-B',
        ]);

        $this->category = Category::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Apparel & Drinks',
            'category_code' => 'APP-DRK',
            'is_active' => true,
        ]);

        app(TenantContext::class)->setStoreId($this->storeA->id);
    }

    /**
     * T-CAT-01: Generate produk varian dengan 3 ukuran dan 2 warna
     * otomatis menghasilkan tepat 6 baris product_variants dengan SKU unik.
     */
    public function test_t_cat_01_matrix_generation_with_3_sizes_and_2_colors_yields_6_unique_variants(): void
    {
        $product = Product::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'Kemeja Oxford Flannel',
            'product_code' => 'KMJ-OXF',
            'product_type' => 'STANDARD',
            'product_price' => 150000.00,
            'standard_cost' => 85000.00,
            'is_active' => true,
        ]);

        // 1. Buat Atribut "Ukuran" dengan 3 opsi: S, M, L
        $sizeAttr = ProductAttribute::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Ukuran',
            'code' => 'SIZE',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $valS = ProductAttributeValue::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'attribute_id' => $sizeAttr->id,
            'value' => 'Small',
            'code' => 'S',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $valM = ProductAttributeValue::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'attribute_id' => $sizeAttr->id,
            'value' => 'Medium',
            'code' => 'M',
            'sort_order' => 2,
            'is_active' => true,
        ]);

        $valL = ProductAttributeValue::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'attribute_id' => $sizeAttr->id,
            'value' => 'Large',
            'code' => 'L',
            'sort_order' => 3,
            'is_active' => true,
        ]);

        // 2. Buat Atribut "Warna" dengan 2 opsi: Merah, Biru
        $colorAttr = ProductAttribute::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Warna',
            'code' => 'COLOR',
            'sort_order' => 2,
            'is_active' => true,
        ]);

        $valRed = ProductAttributeValue::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'attribute_id' => $colorAttr->id,
            'value' => 'Merah',
            'code' => 'RED',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $valBlue = ProductAttributeValue::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'attribute_id' => $colorAttr->id,
            'value' => 'Biru',
            'code' => 'BLU',
            'sort_order' => 2,
            'is_active' => true,
        ]);

        $matrixService = app(ProductVariantMatrixService::class);

        $variants = $matrixService->generateMatrix(
            tenantId: $this->tenant->id,
            productId: $product->id,
            attributeOptions: [
                $sizeAttr->id => [$valS->id, $valM->id, $valL->id],
                $colorAttr->id => [$valRed->id, $valBlue->id],
            ],
            skuPrefix: 'KMJ-OXF',
            priceOverride: 160000.00
        );

        // Verifikasi tepat 6 varian dihasilkan
        $this->assertCount(6, $variants, 'Permutasi 3 ukuran x 2 warna wajib menghasilkan tepat 6 varian.');

        $skus = $variants->pluck('sku')->all();
        $this->assertCount(6, array_unique($skus), 'Seluruh 6 SKU varian wajib unik.');

        // Verifikasi database record count
        $dbCount = ProductVariant::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('product_id', $product->id)
            ->count();
        $this->assertEquals(6, $dbCount);

        // Verifikasi tipe produk induk diubah menjadi VARIANT_PARENT
        $freshProduct = $product->fresh();
        $this->assertEquals('VARIANT_PARENT', $freshProduct->product_type);
        $this->assertTrue((bool) $freshProduct->has_variants);
    }

    /**
     * T-CAT-02: Produk yang dinonaktifkan pada store_product_assortments cabang A
     * tidak muncul di katalog cabang A, tetapi tetap aktif di cabang B.
     */
    public function test_t_cat_02_product_disabled_in_store_a_hidden_in_a_but_visible_in_b(): void
    {
        $product1 = Product::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'Kopi Arabika Premium',
            'product_code' => 'KOP-ARB',
            'product_price' => 45000.00,
            'is_active' => true,
        ]);

        $product2 = Product::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'Teh Melati Organik',
            'product_code' => 'TEH-MLT',
            'product_price' => 25000.00,
            'is_active' => true,
        ]);

        $assortmentService = app(StoreAssortmentService::class);

        // Nonaktifkan Kopi Arabika di Cabang A
        $assortmentService->setProductAvailability(
            tenantId: $this->tenant->id,
            storeId: $this->storeA->id,
            productId: $product1->id,
            isAvailable: false
        );

        // Pastikan di Cabang B Kopi Arabika tetap aktif (atau diaktifkan eksplisit)
        $assortmentService->setProductAvailability(
            tenantId: $this->tenant->id,
            storeId: $this->storeB->id,
            productId: $product1->id,
            isAvailable: true
        );

        // 1. Ambil katalog Cabang A
        $catalogA = $assortmentService->getStoreCatalog(
            tenantId: $this->tenant->id,
            storeId: $this->storeA->id
        );

        $catalogAIds = $catalogA->pluck('id')->all();
        $this->assertNotContains($product1->id, $catalogAIds, 'Kopi Arabika wajib TIDAK muncul di katalog Cabang A.');
        $this->assertContains($product2->id, $catalogAIds, 'Teh Melati wajib tetap muncul di katalog Cabang A.');

        // 2. Ambil katalog Cabang B
        $catalogB = $assortmentService->getStoreCatalog(
            tenantId: $this->tenant->id,
            storeId: $this->storeB->id
        );

        $catalogBIds = $catalogB->pluck('id')->all();
        $this->assertContains($product1->id, $catalogBIds, 'Kopi Arabika wajib TETAP muncul di katalog Cabang B.');
        $this->assertContains($product2->id, $catalogBIds, 'Teh Melati wajib muncul di katalog Cabang B.');
    }

    /**
     * Verifikasi ProductCostHistoryService mencatat append-only dan memperbarui HPP produk.
     */
    public function test_product_cost_history_service_records_append_only(): void
    {
        $product = Product::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'Susu Segar Pasteurisasi',
            'product_code' => 'SSU-PST',
            'product_price' => 30000.00,
            'standard_cost' => 18000.00,
            'is_active' => true,
        ]);

        $costService = app(ProductCostHistoryService::class);

        $history1 = $costService->recordCostChange(
            tenantId: $this->tenant->id,
            productId: $product->id,
            newCost: 19500.00,
            reason: 'Kenaikan harga pakan sapi'
        );

        $this->assertEquals(18000.00, (float) $history1->old_cost);
        $this->assertEquals(19500.00, (float) $history1->new_cost);
        $this->assertEquals(19500.00, (float) $product->fresh()->standard_cost);

        $history2 = $costService->recordCostChange(
            tenantId: $this->tenant->id,
            productId: $product->id,
            newCost: 20000.00,
            reason: 'Penyesuaian biaya logistik pendingin'
        );

        $this->assertEquals(19500.00, (float) $history2->old_cost);
        $this->assertEquals(20000.00, (float) $history2->new_cost);
        $this->assertEquals(20000.00, (float) $product->fresh()->standard_cost);

        $historyRecords = $costService->getCostHistory($this->tenant->id, $product->id);
        $this->assertCount(2, $historyRecords);

        // Buktikan append-only: update melempar Exception
        $this->expectException(\RuntimeException::class);
        $history1->update(['new_cost' => 99999.00]);
    }
}
