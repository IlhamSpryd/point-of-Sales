<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Http\Requests\StoreProductRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductCostHistory;
use App\Models\Store;
use App\Models\TaxClass;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class Increment2MediaCostAndTaxTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private User $user;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $context = app(TenantContext::class);
        $context->clear();

        $this->tenant = Tenant::create([
            'name' => 'Inc2 Test Tenant',
            'subdomain' => 'inc2-test',
            'status' => 'active',
        ]);

        $context->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Inc2 Branch',
            'address' => '456 Avenue',
            'phone' => '08987654321',
            'is_active' => true,
        ]);

        $context->setStoreId($this->store->id);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->actingAs($this->user);
    }

    public function test_tax_class_creation_and_product_relation(): void
    {
        $taxClass = TaxClass::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'PPN-11',
            'name' => 'Pajak Pertambahan Nilai 11%',
            'rate' => '11.0000',
            'is_active' => true,
        ]);

        $category = Category::factory()->create(['tenant_id' => $this->tenant->id]);

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'tax_class_id' => $taxClass->id,
            'is_tax_inclusive' => true,
        ]);

        $this->assertEquals($taxClass->id, $product->taxClass->id);
        $this->assertTrue((bool) $product->is_tax_inclusive);
        $this->assertTrue($taxClass->products->contains($product));
    }

    public function test_product_request_rejects_cross_tenant_tax_class(): void
    {
        $context = app(TenantContext::class);

        $foreignTenant = $context->runWithoutTenant(function () {
            return Tenant::create([
                'name' => 'Foreign Tax Tenant',
                'subdomain' => 'foreign-tax',
                'status' => 'active',
            ]);
        });

        $foreignTax = $context->runAs($foreignTenant->id, null, fn () => TaxClass::create([
            'tenant_id' => $foreignTenant->id,
            'code' => 'FRN-TAX',
            'name' => 'Foreign Tax',
            'rate' => '10.0000',
        ]));

        $cat = Category::factory()->create(['tenant_id' => $this->tenant->id]);

        $rules = (new StoreProductRequest)->rules();

        $validator = Validator::make([
            'category_id' => $cat->id,
            'product_name' => 'Cross Tax Test',
            'product_price' => 20000,
            'tax_class_id' => $foreignTax->id,
        ], $rules);

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('tax_class_id'));
    }

    public function test_product_cost_history_recorded_on_initialization(): void
    {
        $category = Category::factory()->create(['tenant_id' => $this->tenant->id]);
        $service = app(ProductService::class);

        $product = $service->createProduct([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_code' => 'PRD-COST-1',
            'product_name' => 'Cost Initial Product',
            'product_price' => 50000,
            'costing_method' => 'standard',
            'standard_cost' => 25000.00,
        ]);

        $history = ProductCostHistory::where('product_id', $product->id)->first();
        $this->assertNotNull($history);
        $this->assertEquals('0.00', $history->old_cost);
        $this->assertEquals('25000.00', $history->new_cost);
        $this->assertEquals('standard', $history->costing_method);

        // Verify audit log
        $this->assertDatabaseHas('activity_logs', [
            'tenant_id' => $this->tenant->id,
            'action' => 'product.cost_initialized',
            'subject_id' => $product->id,
        ]);
    }

    public function test_product_cost_history_recorded_on_cost_update(): void
    {
        $category = Category::factory()->create(['tenant_id' => $this->tenant->id]);
        $service = app(ProductService::class);

        $product = $service->createProduct([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_code' => 'PRD-COST-2',
            'product_name' => 'Cost Update Product',
            'product_price' => 50000,
            'costing_method' => 'standard',
            'standard_cost' => 20000.00,
        ]);

        // Update cost
        $updated = $service->updateProduct($product, [
            'standard_cost' => 28000.00,
            'cost_change_reason' => 'Vendor price adjustment',
        ]);

        $this->assertEquals('28000.00', $updated->standard_cost);

        $latestHistory = ProductCostHistory::where('product_id', $product->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($latestHistory);
        $this->assertEquals('20000.00', $latestHistory->old_cost);
        $this->assertEquals('28000.00', $latestHistory->new_cost);
        $this->assertEquals('Vendor price adjustment', $latestHistory->reason);

        // Verify audit log
        $this->assertDatabaseHas('activity_logs', [
            'tenant_id' => $this->tenant->id,
            'action' => 'product.cost_changed',
            'subject_id' => $product->id,
        ]);
    }

    public function test_product_cost_history_is_strictly_append_only(): void
    {
        $category = Category::factory()->create(['tenant_id' => $this->tenant->id]);
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'standard_cost' => 10000.00,
        ]);

        $history = ProductCostHistory::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $product->id,
            'costing_method' => 'standard',
            'old_cost' => 0.00,
            'new_cost' => 10000.00,
            'effective_at' => now(),
        ]);

        // UPDATE must fail via trigger
        $updateFailed = false;
        try {
            DB::statement("UPDATE product_cost_histories SET new_cost = 15000.00 WHERE id = {$history->id}");
        } catch (\Throwable $e) {
            $updateFailed = true;
        }
        $this->assertTrue($updateFailed, 'Expected trigger to reject UPDATE on product_cost_histories');

        // DELETE must fail via trigger
        $deleteFailed = false;
        try {
            DB::statement("DELETE FROM product_cost_histories WHERE id = {$history->id}");
        } catch (\Throwable $e) {
            $deleteFailed = true;
        }
        $this->assertTrue($deleteFailed, 'Expected trigger to reject DELETE on product_cost_histories');
    }

    public function test_product_media_upload_and_primary_synchronization(): void
    {
        Storage::fake('public');

        $category = Category::factory()->create(['tenant_id' => $this->tenant->id]);
        $service = app(ProductService::class);

        $file = UploadedFile::fake()->image('signature_blend.jpg', 640, 480);

        $product = $service->createProduct([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_code' => 'PRD-MEDIA-1',
            'product_name' => 'Media Coffee Product',
            'product_price' => 35000,
        ], $file);

        $this->assertNotNull($product->product_photo);
        Storage::disk('public')->assertExists($product->product_photo);

        $primaryMedia = $product->media()->where('is_primary', true)->first();
        $this->assertNotNull($primaryMedia);
        $this->assertEquals($product->product_photo, $primaryMedia->file_path);
        $this->assertEquals('image/jpeg', $primaryMedia->mime_type);
        $this->assertEquals(0, $primaryMedia->sort_order);
    }

    public function test_product_media_add_and_delete(): void
    {
        Storage::fake('public');

        $category = Category::factory()->create(['tenant_id' => $this->tenant->id]);
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
        ]);

        $service = app(ProductService::class);
        $file = UploadedFile::fake()->image('gallery_angle.webp', 800, 600);

        $media = $service->addMedia($product, $file, false, 'Angle Shot');

        $this->assertEquals('Angle Shot', $media->alt_text);
        $this->assertFalse((bool) $media->is_primary);
        Storage::disk('public')->assertExists($media->file_path);

        // Delete media
        $filePath = $media->file_path;
        $deleted = $service->deleteMedia($media);

        $this->assertTrue($deleted);
        $this->assertDatabaseMissing('product_media', ['id' => $media->id]);
        Storage::disk('public')->assertMissing($filePath);
    }
}
