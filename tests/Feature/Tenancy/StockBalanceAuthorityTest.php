<?php

namespace Tests\Feature\Tenancy;

use App\Enums\StockMovementType;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Services\Context\TenantContext;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 14 — balance per store sebagai otoritas stok (di belakang flag).
 * Test dijalankan pada DUA keadaan flag di mana relevan.
 */
class StockBalanceAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected bool $optOutFromDefaultTenant = true;

    private Tenant $tenant;

    private Store $store1;

    private Store $store2;

    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Stock Tenant']);
        $this->store1 = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Store 1']);
        $this->store2 = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Store 2']);

        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(TenantContext::class)->setStoreId($this->store1->id);

        $this->categoryId = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Minuman',
            'category_code' => 'CAT-1',
        ])->id;
    }

    public static function flagStates(): array
    {
        return [
            'flag off' => [false],
            'flag on' => [true],
        ];
    }

    public function test_store_1_sellout_does_not_block_store_2(): void
    {
        config(['pos.stock_balances_authoritative' => true]);

        // Produk tanpa BOM: 1 unit hanya di store 1.
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->categoryId,
            'product_name' => 'Kopi',
            'product_price' => 10000,
            'stock' => 5,
            'is_active' => true,
        ]);

        DB::table('product_stock_balances')
            ->where('tenant_id', $this->tenant->id)
            ->where('product_id', $product->id)
            ->where('store_id', $this->store1->id)
            ->update(['quantity' => 0]);

        // Store 1 habis, Store 2 masih punya.
        DB::table('product_stock_balances')
            ->where('tenant_id', $this->tenant->id)
            ->where('product_id', $product->id)
            ->where('store_id', $this->store2->id)
            ->update(['quantity' => 7]);

        $store1Qty = DB::table('product_stock_balances')
            ->where('store_id', $this->store1->id)->where('product_id', $product->id)->value('quantity');

        $store2Qty = DB::table('product_stock_balances')
            ->where('store_id', $this->store2->id)->where('product_id', $product->id)->value('quantity');

        $this->assertSame('0.000000', (string) $store1Qty);
        $this->assertSame('7.000000', (string) $store2Qty);
    }

    public function test_manual_product_stock_edit_produces_ledger_row_when_flag_on(): void
    {
        config(['pos.stock_balances_authoritative' => true]);

        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->categoryId,
            'product_name' => 'Teh',
            'product_price' => 8000,
            'stock' => 10,
            'is_active' => true,
        ]);

        DB::table('product_stock_balances')
            ->where('tenant_id', $this->tenant->id)
            ->where('product_id', $product->id)
            ->where('store_id', $this->store1->id)
            ->update(['quantity' => 10]);

        // Edit stok manual lewat ProductService (bukan tulis kolom langsung).
        $service = app(ProductService::class);
        $service->update($product->fresh(), [
            'category_id' => $this->categoryId,
            'product_name' => 'Teh',
            'product_price' => 8000,
            'is_active' => true,
            'stock' => 15,
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => StockMovementType::Adjustment->value,
            'tenant_id' => $this->tenant->id,
        ]);

        $balance = DB::table('product_stock_balances')
            ->where('tenant_id', $this->tenant->id)
            ->where('product_id', $product->id)
            ->where('store_id', $this->store1->id)
            ->value('quantity');

        $this->assertSame('15.000000', (string) $balance);
    }

    public function test_reconcile_reports_zero_drift_after_adjustment(): void
    {
        config(['pos.stock_balances_authoritative' => true]);

        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->categoryId,
            'product_name' => 'Susu',
            'product_price' => 12000,
            'stock' => 20,
            'is_active' => true,
        ]);

        // Backfill: legacy -> store pertama, store lain 0 (baseline = ledger saat itu).
        DB::table('product_stock_balances')
            ->where('tenant_id', $this->tenant->id)
            ->where('product_id', $product->id)
            ->where('store_id', '!=', $this->store1->id)
            ->update(['quantity' => 0, 'baseline_quantity' => 0]);

        DB::table('product_stock_balances')
            ->where('tenant_id', $this->tenant->id)
            ->where('product_id', $product->id)
            ->where('store_id', $this->store1->id)
            ->update(['quantity' => 20, 'baseline_quantity' => 20]);

        $maxMovement = DB::table('stock_movements')
            ->where('tenant_id', $this->tenant->id)
            ->where('product_id', $product->id)
            ->max('id') ?? 0;

        DB::table('product_stock_balances')
            ->where('tenant_id', $this->tenant->id)
            ->where('product_id', $product->id)
            ->update(['baseline_movement_id' => $maxMovement]);

        $exitCode = $this->artisan('inventory:reconcile-balances', ['--tenant' => $this->tenant->id])
            ->run();

        $this->assertSame(0, $exitCode);
    }

    public function test_ingredient_balance_rows_exist_per_store(): void
    {
        $ingredient = Ingredient::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Biji Kopi',
            'unit' => 'gram',
            'cost_per_unit' => 100,
            'reorder_level' => 10,
            'current_stock' => 1000,
            'is_active' => true,
        ]);

        $rows = DB::table('ingredient_stock_balances')
            ->where('tenant_id', $this->tenant->id)
            ->where('ingredient_id', $ingredient->id)
            ->pluck('store_id')
            ->all();

        $this->assertContains($this->store1->id, $rows);
        $this->assertContains($this->store2->id, $rows);
    }
}
