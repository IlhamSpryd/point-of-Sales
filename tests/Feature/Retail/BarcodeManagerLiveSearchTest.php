<?php

declare(strict_types=1);

namespace Tests\Feature\Retail;

use App\Livewire\Retail\BarcodeManager;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class BarcodeManagerLiveSearchTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;
    private Store $store;
    private User $user;
    private Product $product;
    private ProductVariant $variant;

    protected function afterSubclassSetUp(): void
    {
        $this->tenant = $this->defaultTenant;
        $this->store = $this->defaultStore;
        $this->user = User::where('tenant_id', $this->tenant->id)->firstOrFail();

        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Fashion',
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_name' => 'Kemeja Batik Sutra Eksklusif',
            'product_code' => 'BATIK-001',
            'product_price' => 250000.00,
            'product_type' => 'variant_parent',
            'status' => 'active',
            'has_variants' => true,
            'track_inventory' => true,
            'is_active' => true,
        ]);

        $this->variant = ProductVariant::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $this->product->id,
            'variant_name' => 'Ukuran XL Merah Marun',
            'sku' => 'BATIK-XL-RED',
            'barcode' => '8991234567890',
            'price' => 275000.00,
            'cost_price' => 150000.00,
            'is_active' => true,
        ]);
    }

    public function test_barcode_manager_searches_by_product_name_without_sql_error(): void
    {
        $this->actingAs($this->user);

        Livewire::test(BarcodeManager::class)
            ->set('search', 'Batik Sutra')
            ->assertStatus(200)
            ->assertSee('Ukuran XL Merah Marun')
            ->assertSee('BATIK-XL-RED')
            ->assertSee('8991234567890');
    }

    public function test_barcode_manager_filters_out_non_matching_search(): void
    {
        $this->actingAs($this->user);

        Livewire::test(BarcodeManager::class)
            ->set('search', 'NonExistentProductQueryXYZ')
            ->assertStatus(200)
            ->assertDontSee('BATIK-XL-RED');
    }
}
