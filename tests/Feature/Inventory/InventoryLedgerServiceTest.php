<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\IngredientStockMovementTypeEnum;
use App\Enums\StockMovementType;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Services\Context\TenantContext;
use App\Services\Inventory\InventoryLedgerService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryLedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    protected bool $optOutFromDefaultTenant = true;

    private Tenant $tenant;

    private Store $store1;

    private Store $store2;

    private Category $category;

    private InventoryLedgerService $service;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = Tenant::create(['name' => 'Ledger Tenant']);
        $this->store1 = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Store Jakarta']);
        $this->store2 = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Store Bandung']);

        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(TenantContext::class)->setStoreId($this->store1->id);

        $this->category = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Kategori Utama',
            'category_code' => 'CAT-LEDGER',
        ]);

        $this->service = app(InventoryLedgerService::class);
    }

    public function test_record_product_inbound_movement_updates_balance_and_cost(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'Susu Kotak',
            'product_price' => 15000,
            'stock' => 0,
            'is_active' => true,
        ]);

        // Penerimaan pertama: 10 unit @ Rp 10.000
        $result1 = $this->service->recordMovement(
            tenantId: $this->tenant->id,
            storeId: $this->store1->id,
            itemType: 'product',
            itemId: $product->id,
            quantityDelta: 10,
            type: StockMovementType::GoodsReceipt,
            unitCost: 10000,
            reason: 'Penerimaan PO #001',
            idempotencyKey: 'grn_test_1'
        );

        $this->assertSame('10.000000', (string) $result1['new_quantity']);

        $balance1 = $this->service->getBalance($this->tenant->id, $this->store1->id, 'product', $product->id);
        $this->assertNotNull($balance1);
        $this->assertEquals(10, (float) $balance1->quantity);
        $this->assertEquals(10000, (float) $balance1->average_unit_cost);
        $this->assertEquals(10000, (float) $balance1->last_unit_cost);
        $this->assertEquals($result1['movement']->id, $balance1->last_movement_id);

        // Periksa kolom mirror legacy products.stock
        $this->assertEquals(10, (int) Product::find($product->id)->stock);

        // Penerimaan kedua: 10 unit @ Rp 12.000 -> WAC baru = Rp 11.000
        $result2 = $this->service->recordMovement(
            tenantId: $this->tenant->id,
            storeId: $this->store1->id,
            itemType: 'product',
            itemId: $product->id,
            quantityDelta: 10,
            type: StockMovementType::GoodsReceipt,
            unitCost: 12000,
            reason: 'Penerimaan PO #002',
            idempotencyKey: 'grn_test_2'
        );

        $balance2 = $this->service->getBalance($this->tenant->id, $this->store1->id, 'product', $product->id);
        $this->assertEquals(20, (float) $balance2->quantity);
        $this->assertEquals(11000, (float) $balance2->average_unit_cost);
        $this->assertEquals(12000, (float) $balance2->last_unit_cost);
        $this->assertEquals(20, (int) Product::find($product->id)->stock);
    }

    public function test_record_ingredient_inbound_movement_updates_balance_and_cost(): void
    {
        $ingredient = Ingredient::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Biji Kopi Arabika',
            'unit' => 'gram',
            'cost_per_unit' => 200,
            'is_active' => true,
            'current_stock' => 0,
            'reorder_level' => 500,
        ]);

        $result = $this->service->recordMovement(
            tenantId: $this->tenant->id,
            storeId: $this->store1->id,
            itemType: 'ingredient',
            itemId: $ingredient->id,
            quantityDelta: '1000.5000',
            type: IngredientStockMovementTypeEnum::GoodsReceipt,
            unitCost: '250.0000',
            reason: 'Penerimaan karung arabika',
            idempotencyKey: 'ing_grn_1'
        );

        $this->assertSame('1000.500000', (string) $result['new_quantity']);

        $balance = $this->service->getBalance($this->tenant->id, $this->store1->id, 'ingredient', $ingredient->id);
        $this->assertEquals(1000.5, (float) $balance->quantity);
        $this->assertEquals(250, (float) $balance->average_unit_cost);
        $this->assertEquals(250, (float) $balance->last_unit_cost);

        $this->assertEquals(1000.5, (float) Ingredient::find($ingredient->id)->current_stock);
    }

    public function test_strict_non_negative_stock_rejection(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'Kue Cokelat',
            'product_price' => 25000,
            'stock' => 0,
            'is_active' => true,
        ]);

        // Masukkan saldo 5 unit
        $this->service->recordMovement(
            tenantId: $this->tenant->id,
            storeId: $this->store1->id,
            itemType: 'product',
            itemId: $product->id,
            quantityDelta: 5,
            type: StockMovementType::AdjustmentIn,
            unitCost: 15000,
            reason: 'Saldo awal'
        );

        // Coba potong 8 unit (melebihi saldo 5) -> Wajib ditolak
        $this->expectException(ValidationException::class);

        $this->service->recordMovement(
            tenantId: $this->tenant->id,
            storeId: $this->store1->id,
            itemType: 'product',
            itemId: $product->id,
            quantityDelta: -8,
            type: StockMovementType::AdjustmentOut,
            reason: 'Barang rusak'
        );
    }

    public function test_idempotency_prevents_duplicate_movement(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'Teh Botol',
            'product_price' => 5000,
            'stock' => 0,
            'is_active' => true,
        ]);

        $key = 'idemp_key_unique_test_123';

        // Panggilan pertama
        $res1 = $this->service->recordMovement(
            tenantId: $this->tenant->id,
            storeId: $this->store1->id,
            itemType: 'product',
            itemId: $product->id,
            quantityDelta: 10,
            type: StockMovementType::GoodsReceipt,
            unitCost: 3500,
            reason: 'Penerimaan',
            idempotencyKey: $key
        );

        // Panggilan kedua dengan kunci yang sama persis
        $res2 = $this->service->recordMovement(
            tenantId: $this->tenant->id,
            storeId: $this->store1->id,
            itemType: 'product',
            itemId: $product->id,
            quantityDelta: 10,
            type: StockMovementType::GoodsReceipt,
            unitCost: 3500,
            reason: 'Penerimaan',
            idempotencyKey: $key
        );

        $this->assertEquals($res1['movement']->id, $res2['movement']->id);

        // Pastikan hanya 1 movement tercatat di DB
        $count = DB::table('stock_movements')->where('idempotency_key', $key)->count();
        $this->assertEquals(1, $count);

        // Saldo tetap 10, tidak terduplikasi menjadi 20
        $balance = $this->service->getBalance($this->tenant->id, $this->store1->id, 'product', $product->id);
        $this->assertEquals(10, (float) $balance->quantity);
    }

    public function test_outbound_mutation_snapshots_average_unit_cost(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'Roti Tawar',
            'product_price' => 12000,
            'stock' => 0,
            'is_active' => true,
        ]);

        // Masuk 10 @ 10.000, lalu 10 @ 20.000 -> WAC = 15.000
        $this->service->recordMovement(
            tenantId: $this->tenant->id,
            storeId: $this->store1->id,
            itemType: 'product',
            itemId: $product->id,
            quantityDelta: 10,
            type: StockMovementType::GoodsReceipt,
            unitCost: 10000
        );
        $this->service->recordMovement(
            tenantId: $this->tenant->id,
            storeId: $this->store1->id,
            itemType: 'product',
            itemId: $product->id,
            quantityDelta: 10,
            type: StockMovementType::GoodsReceipt,
            unitCost: 20000
        );

        // Mutasi keluar (Wastage / AdjustmentOut): snapshot WAC 15.000
        $outResult = $this->service->recordMovement(
            tenantId: $this->tenant->id,
            storeId: $this->store1->id,
            itemType: 'product',
            itemId: $product->id,
            quantityDelta: -4,
            type: StockMovementType::AdjustmentOut,
            reason: 'Kedaluwarsa'
        );

        $this->assertEquals(15000, (float) $outResult['movement']->unit_cost);

        // Saldo berkurang menjadi 16, tapi WAC tetap 15.000
        $balance = $this->service->getBalance($this->tenant->id, $this->store1->id, 'product', $product->id);
        $this->assertEquals(16, (float) $balance->quantity);
        $this->assertEquals(15000, (float) $balance->average_unit_cost);
    }

    public function test_update_reorder_thresholds_validates_and_persists(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'Kopi Sachet',
            'product_price' => 3000,
            'stock' => 0,
            'is_active' => true,
        ]);

        $this->service->updateReorderThresholds(
            tenantId: $this->tenant->id,
            storeId: $this->store1->id,
            itemType: 'product',
            itemId: $product->id,
            minStock: 5,
            reorderPoint: 15,
            maxStock: 50
        );

        $balance = $this->service->getBalance($this->tenant->id, $this->store1->id, 'product', $product->id);
        $this->assertEquals(5, (float) $balance->min_stock_level);
        $this->assertEquals(15, (float) $balance->reorder_point);
        $this->assertEquals(50, (float) $balance->max_stock_level);

        // Validasi kegagalan: max_stock < reorder_point
        $this->expectException(DomainException::class);
        $this->service->updateReorderThresholds(
            tenantId: $this->tenant->id,
            storeId: $this->store1->id,
            itemType: 'product',
            itemId: $product->id,
            minStock: 5,
            reorderPoint: 20,
            maxStock: 10
        );
    }

    public function test_reconciliation_integrity_after_ledger_service_mutations(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'Biskuit Kaleng',
            'product_price' => 45000,
            'stock' => 0,
            'is_active' => true,
        ]);

        $ingredient = Ingredient::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Tepung Terigu',
            'unit' => 'kg',
            'cost_per_unit' => 12000,
            'is_active' => true,
            'current_stock' => 0,
        ]);

        // Mutasi bertahap pada Store 1 dan Store 2
        $this->service->recordMovement($this->tenant->id, $this->store1->id, 'product', $product->id, 25, StockMovementType::GoodsReceipt, 35000);
        $this->service->recordMovement($this->tenant->id, $this->store2->id, 'product', $product->id, 15, StockMovementType::GoodsReceipt, 36000);
        $this->service->recordMovement($this->tenant->id, $this->store1->id, 'product', $product->id, -5, StockMovementType::AdjustmentOut, reason: 'Rusak');

        $this->service->recordMovement($this->tenant->id, $this->store1->id, 'ingredient', $ingredient->id, '50.0000', IngredientStockMovementTypeEnum::GoodsReceipt, '10000.0000');
        $this->service->recordMovement($this->tenant->id, $this->store1->id, 'ingredient', $ingredient->id, '-10.5000', IngredientStockMovementTypeEnum::AdjustmentOut, reason: 'Basi');

        // Jalankan perintah rekonsiliasi
        $this->artisan('inventory:reconcile-balances', ['--tenant' => $this->tenant->id])
            ->assertExitCode(0);

        // Verifikasi skrip SQL audit 05_inventory_balances.sql
        $sqlPath = database_path('sql/verify/05_inventory_balances.sql');
        $sql = file_get_contents($sqlPath);
        $results = DB::select($sql);

        $this->assertNotEmpty($results);
        $this->assertSame('PASS', $results[0]->result);
        $this->assertEquals(0, $results[0]->issues);
    }
}
