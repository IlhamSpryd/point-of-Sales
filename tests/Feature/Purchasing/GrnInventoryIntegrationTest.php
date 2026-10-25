<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Core\Context\TenantContext;
use App\Enums\SupplierStatusEnum;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\GoodsReceivedNoteService;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GrnInventoryIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private User $ownerUser;

    private User $warehouseUser;

    private Supplier $supplier;

    private Product $product;

    private Ingredient $ingredient;

    private PurchaseOrderService $poService;

    private GoodsReceivedNoteService $grnService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = Tenant::create([
            'name' => 'Resto Berkah Abadi',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'status' => 'active',
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cabang Utama Jakarta',
            'status' => 'active',
        ]);

        app(TenantContext::class)->setStoreId($this->store->id);

        $ownerRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Owner',
            'is_admin' => true,
        ]);

        $warehouseRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Warehouse Staff',
            'is_admin' => false,
            'permissions' => [
                'purchasing.grn.view',
                'purchasing.grn.create',
                'purchasing.grn.post',
                'purchasing.grn.reverse',
            ],
        ]);

        $this->ownerUser = User::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'role_id' => $ownerRole->id,
            'name' => 'Pak Budi Pemilik',
            'email' => 'owner.berkah@test.com',
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);

        $this->warehouseUser = User::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'role_id' => $warehouseRole->id,
            'name' => 'Doni Gudang',
            'email' => 'doni.gudang@test.com',
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);

        $this->ownerUser->stores()->attach($this->store->id, ['tenant_id' => $this->tenant->id]);
        $this->warehouseUser->stores()->attach($this->store->id, ['tenant_id' => $this->tenant->id]);

        $this->supplier = Supplier::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'SUP-BERKAH-01',
            'name' => 'PT Pangan Sumber Makmur',
            'status' => SupplierStatusEnum::Active,
            'payment_terms_days' => 14,
            'currency_code' => 'IDR',
        ]);

        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Bahan Makanan',
            'slug' => 'bahan-makanan',
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'name' => 'Beras Pandan Wangi 5kg',
            'sku' => 'PRD-BERAS-5K',
            'price' => '85000.00',
            'stock' => 0,
        ]);

        $this->ingredient = Ingredient::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Gula Pasir Industri',
            'sku' => 'ING-GULA-KG',
            'unit' => 'KG',
            'current_cost' => '15000.00',
            'current_stock' => 0,
        ]);

        $this->poService = app(PurchaseOrderService::class);
        $this->grnService = app(GoodsReceivedNoteService::class);
    }

    private function createReleasedPo(string $productQty = '10.0000', string $ingredientQty = '25.5000'): PurchaseOrder
    {
        $po = $this->poService->createDirectPurchaseOrder(
            [
                'store_id' => $this->store->id,
                'supplier_id' => $this->supplier->id,
                'order_date' => now()->toDateString(),
            ],
            [
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $this->product->id,
                    'purchase_uom' => 'BAG',
                    'uom_conversion_factor' => '1.000000',
                    'ordered_qty' => $productQty,
                    'unit_price' => '80000.0000',
                ],
                [
                    'purchasable_type' => 'ingredient',
                    'purchasable_id' => $this->ingredient->id,
                    'purchase_uom' => 'KG',
                    'uom_conversion_factor' => '1.000000',
                    'ordered_qty' => $ingredientQty,
                    'unit_price' => '14500.0000',
                ],
            ],
            $this->ownerUser
        );

        $po = $this->poService->submitPurchaseOrder($po, $this->ownerUser);

        return $this->poService->releasePurchaseOrder($po, $this->ownerUser);
    }

    /**
     * AT-11: Posting GRN untuk Product menulis ke stock_movements,
     * memperbarui product_stock_balances, dan menyinkronkan products.stock secara atomik.
     */
    public function test_grn_posts_product_movements_and_updates_balances(): void
    {
        $po = $this->createReleasedPo('15.0000', '10.0000');
        $productLine = $po->lines()->where('purchasable_type', 'product')->firstOrFail();

        $grn = $this->grnService->createGoodsReceivedNote(
            $po,
            ['supplier_delivery_note_no' => 'SJ-PRD-001'],
            [
                [
                    'purchase_order_line_id' => $productLine->id,
                    'received_qty' => '15.0000',
                    'accepted_qty' => '15.0000',
                    'rejected_qty' => '0.0000',
                    'batch_number' => 'BATCH-BERAS-202610',
                    'expiry_date' => now()->addMonths(6)->toDateString(),
                ],
            ],
            $this->warehouseUser
        );

        $postedGrn = $this->grnService->postGrn($grn, $this->warehouseUser);
        $grnLine = $postedGrn->lines()->where('purchasable_type', 'product')->firstOrFail();

        // 1. Asersi stock_movement_id terisi
        $this->assertNotNull($grnLine->stock_movement_id);

        // 2. Asersi record di stock_movements
        $movement = DB::table('stock_movements')
            ->where('id', $grnLine->stock_movement_id)
            ->first();

        $this->assertNotNull($movement);
        $this->assertEquals($this->tenant->id, $movement->tenant_id);
        $this->assertEquals($this->store->id, $movement->store_id);
        $this->assertEquals($this->product->id, $movement->product_id);
        $this->assertEquals('goods_receipt', $movement->type);
        $this->assertEquals(15, (int) $movement->quantity);
        $this->assertEquals('80000.0000', $movement->unit_cost);

        // 3. Asersi saldo di product_stock_balances
        $balance = DB::table('product_stock_balances')
            ->where('tenant_id', $this->tenant->id)
            ->where('store_id', $this->store->id)
            ->where('product_id', $this->product->id)
            ->first();

        $this->assertNotNull($balance);
        $this->assertEquals('15.000000', (string) $balance->quantity);
        $this->assertEquals('80000.0000', (string) $balance->average_unit_cost);
        $this->assertEquals('80000.0000', (string) $balance->last_unit_cost);

        // 4. Asersi kolom mirror legacy products.stock
        $this->product->refresh();
        $this->assertEquals(15, (int) $this->product->stock);
    }

    /**
     * AT-11: Posting GRN untuk Ingredient (pecahan) menulis ke ingredient_stock_movements,
     * memperbarui ingredient_stock_balances, dan menyinkronkan ingredients.current_stock.
     */
    public function test_grn_posts_ingredient_movements_and_updates_balances(): void
    {
        $po = $this->createReleasedPo('5.0000', '25.7500');
        $ingredientLine = $po->lines()->where('purchasable_type', 'ingredient')->firstOrFail();

        $grn = $this->grnService->createGoodsReceivedNote(
            $po,
            ['supplier_delivery_note_no' => 'SJ-ING-001'],
            [
                [
                    'purchase_order_line_id' => $ingredientLine->id,
                    'received_qty' => '25.7500',
                    'accepted_qty' => '25.7500',
                    'rejected_qty' => '0.0000',
                    'batch_number' => 'LOT-GULA-889',
                ],
            ],
            $this->warehouseUser
        );

        $postedGrn = $this->grnService->postGrn($grn, $this->warehouseUser);
        $grnLine = $postedGrn->lines()->where('purchasable_type', 'ingredient')->firstOrFail();

        // 1. Asersi ingredient_stock_movement_id terisi
        $this->assertNotNull($grnLine->ingredient_stock_movement_id);

        // 2. Asersi record di ingredient_stock_movements
        $movement = DB::table('ingredient_stock_movements')
            ->where('id', $grnLine->ingredient_stock_movement_id)
            ->first();

        $this->assertNotNull($movement);
        $this->assertEquals($this->tenant->id, $movement->tenant_id);
        $this->assertEquals($this->store->id, $movement->store_id);
        $this->assertEquals($this->ingredient->id, $movement->ingredient_id);
        $this->assertEquals('goods_receipt', $movement->type);
        $this->assertEquals('25.7500', (string) $movement->quantity);
        $this->assertEquals('14500.0000', (string) $movement->unit_cost);

        // 3. Asersi saldo di ingredient_stock_balances
        $balance = DB::table('ingredient_stock_balances')
            ->where('tenant_id', $this->tenant->id)
            ->where('store_id', $this->store->id)
            ->where('ingredient_id', $this->ingredient->id)
            ->first();

        $this->assertNotNull($balance);
        $this->assertEquals('25.750000', (string) $balance->quantity);
        $this->assertEquals('14500.0000', (string) $balance->average_unit_cost);

        // 4. Asersi kolom mirror legacy ingredients.current_stock
        $this->ingredient->refresh();
        $this->assertEquals('25.7500', (string) $this->ingredient->current_stock);
    }

    /**
     * AT-11: Pembalikan GRN (Reversal) mencatat gerakan pembalik goods_receipt_reversal
     * dan menegakkan invariant non-negative stock (gagal jika stok sudah tidak mencukupi).
     */
    public function test_grn_reversal_posts_outbound_movements_and_guards_non_negative_stock(): void
    {
        $po = $this->createReleasedPo('10.0000', '10.0000');
        $productLine = $po->lines()->where('purchasable_type', 'product')->firstOrFail();

        $grn = $this->grnService->createGoodsReceivedNote(
            $po,
            ['supplier_delivery_note_no' => 'SJ-REVERSAL-TEST'],
            [
                [
                    'purchase_order_line_id' => $productLine->id,
                    'received_qty' => '10.0000',
                    'accepted_qty' => '10.0000',
                    'rejected_qty' => '0.0000',
                ],
            ],
            $this->warehouseUser
        );

        $postedGrn = $this->grnService->postGrn($grn, $this->warehouseUser);

        // Saldo sekarang 10
        $this->product->refresh();
        $this->assertEquals(10, (int) $this->product->stock);

        // Skenario A: Reversal normal saat saldo mencukupi
        $this->grnService->reverseGrn($postedGrn, $this->warehouseUser, 'Kesalahan administrasi penerimaan');

        // Asersi saldo kembali ke 0
        $this->product->refresh();
        $this->assertEquals(0, (int) $this->product->stock);

        // Asersi ada baris pembalik di stock_movements
        $reversalMovement = DB::table('stock_movements')
            ->where('tenant_id', $this->tenant->id)
            ->where('product_id', $this->product->id)
            ->where('type', 'goods_receipt_reversal')
            ->latest('id')
            ->first();

        $this->assertNotNull($reversalMovement);
        $this->assertEquals(-10, (int) $reversalMovement->quantity);

        // Skenario B: Uji Invariant Non-Negative Stock
        // Buat GRN baru lagi sejumlah 5 item
        $po2 = $this->createReleasedPo('5.0000', '5.0000');
        $po2Line = $po2->lines()->where('purchasable_type', 'product')->firstOrFail();

        $grn2 = $this->grnService->createGoodsReceivedNote(
            $po2,
            ['supplier_delivery_note_no' => 'SJ-GRN2'],
            [
                [
                    'purchase_order_line_id' => $po2Line->id,
                    'received_qty' => '5.0000',
                    'accepted_qty' => '5.0000',
                    'rejected_qty' => '0.0000',
                ],
            ],
            $this->warehouseUser
        );
        $postedGrn2 = $this->grnService->postGrn($grn2, $this->warehouseUser);

        // Simulasikan stok terpakai oleh POS hingga saldo tersisa hanya 2
        DB::table('product_stock_balances')
            ->where('tenant_id', $this->tenant->id)
            ->where('store_id', $this->store->id)
            ->where('product_id', $this->product->id)
            ->update(['quantity' => 2]);

        // Mencoba reverse GRN2 (yang butuh mengurangi 5 item, padahal stok cuma 2) -> Wajib dilempar ValidationException!
        $this->expectException(ValidationException::class);
        $this->grnService->reverseGrn($postedGrn2, $this->warehouseUser, 'Coba reverse saat stok habis');
    }
}
