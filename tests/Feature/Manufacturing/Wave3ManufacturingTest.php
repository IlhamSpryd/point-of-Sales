<?php

declare(strict_types=1);

namespace Tests\Feature\Manufacturing;

use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Order;
use App\Models\Tenant;
use App\Modules\Manufacturing\Models\Ingredient;
use App\Modules\Manufacturing\Models\IngredientStockBalance;
use App\Modules\Manufacturing\Models\IngredientStockMovement;
use App\Modules\Manufacturing\Models\KitchenWasteLog;
use App\Modules\Manufacturing\Models\ModifierIngredient;
use App\Modules\Manufacturing\Models\ProductIngredient;
use App\Modules\Manufacturing\Services\KitchenWasteService;
use App\Modules\Manufacturing\Services\ProductFulfillmentResolverService;
use App\Modules\Manufacturing\Services\RecipeCostingService;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Settings\Models\Store;
use App\Modules\Users\Models\User;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class Wave3ManufacturingTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;
    private Store $store;
    private Category $category;
    private User $user;

    protected function afterSubclassSetUp(): void
    {
        $this->tenant = Tenant::create([
            'name' => 'F&B Gourmet Group ' . uniqid(),
            'code' => 'FBG-' . strtoupper(Str::random(4)),
            'status' => 'ACTIVE',
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);
        if (class_exists(\App\Core\Context\TenantContext::class) && app()->bound(\App\Core\Context\TenantContext::class)) {
            app(\App\Core\Context\TenantContext::class)->setTenantId($this->tenant->id);
        }

        $this->store = Store::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'code' => 'KTC-' . uniqid(),
            'name' => 'Resto Cabang Senopati',
            'is_active' => true,
        ]);

        app(TenantContext::class)->setStoreId($this->store->id);
        if (class_exists(\App\Core\Context\TenantContext::class) && app()->bound(\App\Core\Context\TenantContext::class)) {
            app(\App\Core\Context\TenantContext::class)->setStoreId($this->store->id);
        }

        $this->category = Category::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'category_code' => 'FNB-' . uniqid(),
            'category_name' => 'Main Course F&B',
            'is_active' => true,
        ]);

        $this->user = User::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Chef Head Kitchen',
            'email' => 'chef.' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
    }

    /**
     * T-MFG-01: Penjualan kasir F&B untuk produk dengan resep BOM (dan modifier)
     * otomatis memotong stok bahan baku di ingredient_stock_balances dan
     * mencatat mutasi append-only di ingredient_stock_movements.
     */
    public function test_t_mfg_01_selling_fb_product_with_bom_recipe_and_modifiers_deducts_ingredient_stock(): void
    {
        // 1. Buat master bahan mentah: Beras (kg), Telur (butir), Daging Ayam (kg), Minyak Goreng (ml)
        $beras = Ingredient::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'ingredient_code' => 'ING-BRS-' . uniqid(),
            'name' => 'Beras Organik',
            'unit' => 'kg',
            'current_stock' => 50.0000,
            'cost_per_unit' => 14000.00,
            'is_active' => true,
        ]);

        $telur = Ingredient::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'ingredient_code' => 'ING-TLR-' . uniqid(),
            'name' => 'Telur Ayam',
            'unit' => 'butir',
            'current_stock' => 100.0000,
            'cost_per_unit' => 2500.00,
            'is_active' => true,
        ]);

        $ayam = Ingredient::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'ingredient_code' => 'ING-AYM-' . uniqid(),
            'name' => 'Daging Ayam Fillet',
            'unit' => 'kg',
            'current_stock' => 20.0000,
            'cost_per_unit' => 45000.00,
            'is_active' => true,
        ]);

        // Bahan baku modifier: Extra Keju Mozzarella (gram)
        $keju = Ingredient::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'ingredient_code' => 'ING-KJU-' . uniqid(),
            'name' => 'Keju Mozzarella',
            'unit' => 'gram',
            'current_stock' => 1000.0000,
            'cost_per_unit' => 150.00,
            'is_active' => true,
        ]);

        // Inisialisasi saldo di ingredient_stock_balances untuk cabang Senopati
        $this->initIngredientBalance($beras->id, '50.0000', '14000.00');
        $this->initIngredientBalance($telur->id, '100.0000', '2500.00');
        $this->initIngredientBalance($ayam->id, '20.0000', '45000.00');
        $this->initIngredientBalance($keju->id, '1000.0000', '150.00');

        // 2. Buat produk jadi: Nasi Goreng Spesial
        $nasiGoreng = Product::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'Nasi Goreng Spesial',
            'product_code' => 'NASGOR-SP-' . uniqid(),
            'product_price' => 45000.00,
            'is_active' => true,
        ]);

        // Hubungkan resep BOM (product_ingredients):
        // 1 Porsi Nasi Goreng butuh:
        // - Beras: 0.20 kg
        // - Telur: 1 butir
        // - Ayam: 0.10 kg
        ProductIngredient::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $nasiGoreng->id,
            'ingredient_id' => $beras->id,
            'quantity_required' => 0.20,
        ]);

        ProductIngredient::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $nasiGoreng->id,
            'ingredient_id' => $telur->id,
            'quantity_required' => 1.0,
        ]);

        ProductIngredient::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $nasiGoreng->id,
            'ingredient_id' => $ayam->id,
            'quantity_required' => 0.10,
        ]);

        // Buat Modifier Group & Modifier: Extra Mozzarella
        $modGroup = ModifierGroup::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Topping Pilihan',
            'selection_type' => 'multiple',
            'is_required' => false,
        ]);

        $extraCheeseMod = Modifier::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'modifier_group_id' => $modGroup->id,
            'name' => 'Extra Mozzarella Melt',
            'price' => 8000.00,
            'is_active' => true,
        ]);

        // Hubungkan modifier ke bahan mentah: 50 gram keju
        ModifierIngredient::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'modifier_id' => $extraCheeseMod->id,
            'ingredient_id' => $keju->id,
            'quantity_required' => 50.0,
        ]);

        // 3. Simulasikan Penjualan Kasir F&B:
        // Pesanan: 5 porsi Nasi Goreng Spesial DENGAN Extra Mozzarella
        $order = Order::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'order_code' => 'ORD-MFG-' . Str::upper(Str::random(8)),
            'idempotency_key' => Str::uuid()->toString(),
            'order_date' => now()->toDateString(),
            'order_amount' => 125000,
        ]);
        $orderId = (int) $order->id;
        $orderItems = [
            [
                'product_id' => $nasiGoreng->id,
                'quantity' => 5,
                'modifier_ids' => [$extraCheeseMod->id],
            ],
        ];

        $fulfillmentService = app(ProductFulfillmentResolverService::class);
        $deductions = $fulfillmentService->deductRecipeForOrder(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            orderId: $orderId,
            items: $orderItems,
            userId: $this->user->id
        );

        $this->assertCount(4, $deductions, 'Harus ada 4 bahan yang dipotong (beras, telur, ayam, keju).');

        // Total ekspektasi pemotongan untuk 5 porsi:
        // - Beras: 5 x 0.20 = 1.00 kg -> Saldo sisa: 50 - 1 = 49 kg
        // - Telur: 5 x 1 = 5 butir -> Saldo sisa: 100 - 5 = 95 butir
        // - Ayam: 5 x 0.10 = 0.50 kg -> Saldo sisa: 20 - 0.5 = 19.5 kg
        // - Keju: 5 x 50g = 250 gram -> Saldo sisa: 1000 - 250 = 750 gram

        $berasBalance = $this->getIngredientBalance($beras->id);
        $telurBalance = $this->getIngredientBalance($telur->id);
        $ayamBalance = $this->getIngredientBalance($ayam->id);
        $kejuBalance = $this->getIngredientBalance($keju->id);

        $this->assertEquals(49.0000, (float) $berasBalance);
        $this->assertEquals(95.0000, (float) $telurBalance);
        $this->assertEquals(19.5000, (float) $ayamBalance);
        $this->assertEquals(750.0000, (float) $kejuBalance);

        // Verifikasi mutasi append-only di ingredient_stock_movements
        $movements = IngredientStockMovement::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('store_id', $this->store->id)
            ->where('order_id', $orderId)
            ->get();

        $this->assertCount(4, $movements);
        foreach ($movements as $m) {
            $this->assertStringContainsString('sale', strtolower($m->type));
            $this->assertLessThan(0, (float) $m->quantity);
        }
    }

    /**
     * T-MFG-02: Pembatalan (void) pesanan kasir memulihkan stok bahan baku dapur
     * secara otomatis melalui mutasi kompensasi VOID_RESTORE.
     */
    public function test_t_mfg_02_voiding_order_restores_ingredient_stock_via_compensation_movement(): void
    {
        $dagingSapi = Ingredient::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'ingredient_code' => 'ING-SPI-' . uniqid(),
            'name' => 'Daging Sapi Sirloin',
            'unit' => 'kg',
            'current_stock' => 10.0000,
            'cost_per_unit' => 120000.00,
            'is_active' => true,
        ]);

        $this->initIngredientBalance($dagingSapi->id, '10.0000', '120000.00');

        $steak = Product::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'Sirloin Steak 200g',
            'product_code' => 'STK-200-' . uniqid(),
            'product_price' => 150000.00,
            'is_active' => true,
        ]);

        // Resep: 1 porsi steak butuh 0.20 kg daging sapi
        ProductIngredient::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $steak->id,
            'ingredient_id' => $dagingSapi->id,
            'quantity_required' => 0.20,
        ]);

        $fulfillmentService = app(ProductFulfillmentResolverService::class);
        $order = Order::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'order_code' => 'ORD-MFG-VOID-' . Str::upper(Str::random(8)),
            'idempotency_key' => Str::uuid()->toString(),
            'order_date' => now()->toDateString(),
            'order_amount' => 300000,
        ]);
        $orderId = (int) $order->id;
        $orderItems = [
            [
                'product_id' => $steak->id,
                'quantity' => 2, // 2 porsi = 0.40 kg
            ],
        ];

        // 1. Eksekusi penjualan kasir: Daging berkurang dari 10.0 kg -> 9.60 kg
        $fulfillmentService->deductRecipeForOrder(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            orderId: $orderId,
            items: $orderItems,
            userId: $this->user->id
        );

        $balanceAfterSale = $this->getIngredientBalance($dagingSapi->id);
        $this->assertEquals(9.6000, (float) $balanceAfterSale);

        // 2. Kasir membatalkan pesanan (Void) -> Sistem merestorasi bahan mentah
        $restorations = $fulfillmentService->restoreRecipeForVoid(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            orderId: $orderId,
            items: $orderItems,
            userId: $this->user->id,
            reason: 'Pelanggan membatalkan pesanan steak sebelum dimasak'
        );

        $this->assertCount(1, $restorations);
        $this->assertEquals('0.4000', $restorations[0]['quantity_restored']);

        // Saldo kembali persis 10.0 kg
        $balanceAfterVoid = $this->getIngredientBalance($dagingSapi->id);
        $this->assertEquals(10.0000, (float) $balanceAfterVoid);

        // Periksa mutasi kompensasi di ingredient_stock_movements
        $restoreMovement = IngredientStockMovement::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('store_id', $this->store->id)
            ->where('order_id', $orderId)
            ->where('quantity', '>', 0)
            ->first();

        $this->assertNotNull($restoreMovement);
        $this->assertStringContainsString('restore', strtolower($restoreMovement->type));
        $this->assertEquals(0.4000, (float) $restoreMovement->quantity);
    }

    /**
     * Uji kalkulasi HPP resep dinamis (RecipeCostingService).
     */
    public function test_dynamic_recipe_and_modifier_costing(): void
    {
        $costingService = app(RecipeCostingService::class);

        $kopi = Ingredient::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'ingredient_code' => 'ING-KPI-' . uniqid(),
            'name' => 'Biji Kopi Arabika',
            'unit' => 'gram',
            'cost_per_unit' => 250.00, // Rp 250 / gram
            'is_active' => true,
        ]);

        $susu = Ingredient::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'ingredient_code' => 'ING-SSU-' . uniqid(),
            'name' => 'Fresh Milk',
            'unit' => 'ml',
            'cost_per_unit' => 20.00, // Rp 20 / ml
            'is_active' => true,
        ]);

        $cappuccino = Product::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'Hot Cappuccino',
            'product_code' => 'HOT-CAP-' . uniqid(),
            'product_price' => 35000.00,
            'is_active' => true,
        ]);

        // Resep Cappuccino:
        // - 18g Kopi x Rp 250 = Rp 4.500
        // - 150ml Susu x Rp 20 = Rp 3.000
        // Total Recipe Cost = Rp 7.500
        ProductIngredient::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $cappuccino->id,
            'ingredient_id' => $kopi->id,
            'quantity_required' => 18.0,
        ]);

        ProductIngredient::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $cappuccino->id,
            'ingredient_id' => $susu->id,
            'quantity_required' => 150.0,
        ]);

        $recipeCost = $costingService->calculateRecipeCost($this->tenant->id, $cappuccino->id);
        $this->assertEquals('7500.00', $recipeCost);
    }

    /**
     * Uji pencatatan limbah dapur (KitchenWasteService).
     */
    public function test_kitchen_waste_recording_and_ledger_entry(): void
    {
        $telur = Ingredient::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'ingredient_code' => 'ING-TLR-WST-' . uniqid(),
            'name' => 'Telur Bebek',
            'unit' => 'butir',
            'current_stock' => 50.0000,
            'cost_per_unit' => 3000.00,
            'is_active' => true,
        ]);

        $this->initIngredientBalance($telur->id, '50.0000', '3000.00');

        $wasteService = app(KitchenWasteService::class);

        // Catat 5 butir telur pecah saat persiapan dapur
        $log = $wasteService->recordWaste(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            ingredientId: $telur->id,
            quantity: 5,
            reason: 'Telur pecah di rak penyimpanan saat unboxing',
            userId: $this->user->id
        );

        $this->assertInstanceOf(KitchenWasteLog::class, $log);
        $this->assertEquals(5.0000, (float) $log->quantity);
        $this->assertEquals(15000.0000, (float) $log->total_loss_amount);

        // Saldo berkurang dari 50 -> 45
        $remainingBalance = $this->getIngredientBalance($telur->id);
        $this->assertEquals(45.0000, (float) $remainingBalance);
    }

    private function initIngredientBalance(int $ingredientId, string $qty, string $cost): void
    {
        $existing = DB::table('ingredient_stock_balances')
            ->where('tenant_id', $this->tenant->id)
            ->where('store_id', $this->store->id)
            ->where('ingredient_id', $ingredientId)
            ->first();

        if ($existing) {
            DB::table('ingredient_stock_balances')
                ->where('tenant_id', $this->tenant->id)
                ->where('store_id', $this->store->id)
                ->where('ingredient_id', $ingredientId)
                ->update([
                    'quantity' => $qty,
                    'reserved_quantity' => '0.0000',
                    'baseline_quantity' => $qty,
                    'baseline_movement_id' => 0,
                    'average_unit_cost' => $cost,
                    'last_unit_cost' => $cost,
                    'updated_at' => now(),
                ]);
        } else {
            DB::table('ingredient_stock_balances')->insert([
                'tenant_id' => $this->tenant->id,
                'store_id' => $this->store->id,
                'ingredient_id' => $ingredientId,
                'quantity' => $qty,
                'reserved_quantity' => '0.0000',
                'baseline_quantity' => $qty,
                'baseline_movement_id' => 0,
                'average_unit_cost' => $cost,
                'last_unit_cost' => $cost,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function getIngredientBalance(int $ingredientId): float
    {
        $row = IngredientStockBalance::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('store_id', $this->store->id)
            ->where('ingredient_id', $ingredientId)
            ->first();

        return $row ? (float) $row->quantity : 0.0;
    }
}
