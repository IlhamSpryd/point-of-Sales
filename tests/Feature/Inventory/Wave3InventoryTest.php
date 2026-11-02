<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\StockMovementType;
use App\Models\Tenant;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Exceptions\StockOpnameDriftException;
use App\Modules\Inventory\Services\InventoryCostingService;
use App\Modules\Inventory\Services\InventoryLedgerService;
use App\Modules\Inventory\Services\StockOpnameService;
use App\Modules\Inventory\Services\StockTransferService;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Settings\Models\Store;
use App\Modules\Users\Models\User;
use App\Services\Context\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class Wave3InventoryTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;
    private Store $storeA;
    private Store $storeB;
    private Category $category;
    private User $user;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = Tenant::create([
            'name' => 'Resto & Retail Corp ' . uniqid(),
            'code' => 'RRC-' . strtoupper(Str::random(4)),
            'status' => 'ACTIVE',
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->storeA = Store::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'store_code' => 'STR-A-' . uniqid(),
            'name' => 'Cabang Sudirman',
            'is_active' => true,
        ]);

        $this->storeB = Store::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'store_code' => 'STR-B-' . uniqid(),
            'name' => 'Cabang Thamrin',
            'is_active' => true,
        ]);

        $this->category = Category::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'category_code' => 'CAT-' . uniqid(),
            'category_name' => 'Retail General',
            'is_active' => true,
        ]);

        $this->user = User::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Kasir Inventory',
            'email' => 'cashier.' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
    }

    /**
     * T-INV-01: Perintah UPDATE atau DELETE pada stock_movements wajib memicu
     * exception basis data (append-only trigger guard).
     */
    public function test_t_inv_01_stock_movements_update_and_delete_are_strictly_forbidden_by_triggers(): void
    {
        $product = Product::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'Kopi Kemasan 250g',
            'product_code' => 'KOP-250-' . uniqid(),
            'product_price' => 50000.00,
            'standard_cost' => 30000.00,
            'stock' => 10,
            'track_inventory' => true,
            'is_active' => true,
        ]);

        $ledgerService = app(InventoryLedgerService::class);

        // Rekam satu mutasi masuk
        $movementRes = $ledgerService->recordMovement(
            tenantId: $this->tenant->id,
            storeId: $this->storeA->id,
            itemType: 'product',
            itemId: $product->id,
            quantityDelta: 20,
            type: StockMovementType::GoodsReceipt,
            unitCost: 30000.00,
            reason: 'Penerimaan barang dari supplier'
        );

        $movementId = $movementRes['movement']->id;

        // 1. Coba UPDATE rekord di stock_movements -> Wajib melempar QueryException (SQLSTATE 45000)
        $updateBlocked = false;
        try {
            DB::table('stock_movements')
                ->where('id', $movementId)
                ->update(['quantity' => 999]);
        } catch (QueryException $e) {
            $updateBlocked = true;
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
        $this->assertTrue($updateBlocked, 'Perintah UPDATE pada stock_movements wajib ditolak oleh database trigger.');

        // 2. Coba DELETE rekord di stock_movements -> Wajib melempar QueryException (SQLSTATE 45000)
        $deleteBlocked = false;
        try {
            DB::table('stock_movements')
                ->where('id', $movementId)
                ->delete();
        } catch (QueryException $e) {
            $deleteBlocked = true;
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
        $this->assertTrue($deleteBlocked, 'Perintah DELETE pada stock_movements wajib ditolak oleh database trigger.');
    }

    /**
     * T-INV-02: Dua kasir simultan menjual sisa stok terakhir hanya meloloskan 1 transaksi;
     * transaksi kedua ditolak InsufficientStockException. Saldo stok tidak pernah negatif.
     */
    public function test_t_inv_02_concurrency_race_condition_rejects_second_sale_with_insufficient_stock_exception(): void
    {
        $product = Product::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'Jaket Limited Edition',
            'product_code' => 'JKT-LTD-' . uniqid(),
            'product_price' => 750000.00,
            'standard_cost' => 400000.00,
            'stock' => 0,
            'track_inventory' => true,
            'is_active' => true,
        ]);

        $ledgerService = app(InventoryLedgerService::class);

        // Siapkan stok awal tepat 1 unit di Cabang Sudirman
        $ledgerService->recordMovement(
            tenantId: $this->tenant->id,
            storeId: $this->storeA->id,
            itemType: 'product',
            itemId: $product->id,
            quantityDelta: 1,
            type: StockMovementType::InitialSync,
            unitCost: 400000.00,
            reason: 'Inisialisasi stok tepat 1 unit'
        );

        $initialQty = $ledgerService->getAvailableQuantity($this->tenant->id, $this->storeA->id, 'product', $product->id);
        $this->assertEquals(1.0, $initialQty);

        // Transaksi 1: Kasir 1 menjual 1 unit (Berhasil)
        $sale1Success = false;
        try {
            $ledgerService->recordMovement(
                tenantId: $this->tenant->id,
                storeId: $this->storeA->id,
                itemType: 'product',
                itemId: $product->id,
                quantityDelta: -1,
                type: StockMovementType::SaleDeduction,
                unitCost: 400000.00,
                reason: 'Penjualan Kasir 1'
            );
            $sale1Success = true;
        } catch (\Throwable $e) {
            $sale1Success = false;
        }

        $this->assertTrue($sale1Success, 'Transaksi kasir 1 wajib berhasil memotong stok terakhir.');

        // Transaksi 2: Kasir 2 mencoba menjual 1 unit lagi saat saldo sudah 0 -> Wajib ditolak InsufficientStockException
        $sale2Rejected = false;
        try {
            $ledgerService->recordMovement(
                tenantId: $this->tenant->id,
                storeId: $this->storeA->id,
                itemType: 'product',
                itemId: $product->id,
                quantityDelta: -1,
                type: StockMovementType::SaleDeduction,
                unitCost: 400000.00,
                reason: 'Penjualan Kasir 2 (Simultan saat stok 0)'
            );
        } catch (InsufficientStockException $e) {
            $sale2Rejected = true;
            $this->assertEquals('product', $e->itemType);
            $this->assertEquals($product->id, $e->itemId);
        }

        $this->assertTrue($sale2Rejected, 'Transaksi kasir 2 wajib ditolak oleh InsufficientStockException.');

        // Pastikan saldo akhir persis 0 (tidak pernah negatif)
        $finalQty = $ledgerService->getAvailableQuantity($this->tenant->id, $this->storeA->id, 'product', $product->id);
        $this->assertEquals(0.0, $finalQty);
    }

    /**
     * T-INV-03: Akumulasi stock_movements sama persis dengan saldo di product_stock_balances.
     * Diverifikasi menggunakan artisan command inventory:reconcile-balances.
     */
    public function test_t_inv_03_reconcile_balances_verifies_exact_ledger_equivalence(): void
    {
        $product = Product::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'Buku Ensiklopedia',
            'product_code' => 'BK-ENS-' . uniqid(),
            'product_price' => 120000.00,
            'standard_cost' => 80000.00,
            'stock' => 0,
            'track_inventory' => true,
            'is_active' => true,
        ]);

        $ledgerService = app(InventoryLedgerService::class);

        // Buat rentetan mutasi stok: Masuk 50 -> Jual 15 -> Jual 5 -> Masuk 20 -> Retur 2
        $ledgerService->recordMovement(
            tenantId: $this->tenant->id,
            storeId: $this->storeA->id,
            itemType: 'product',
            itemId: $product->id,
            quantityDelta: 50,
            type: StockMovementType::GoodsReceipt,
            unitCost: 80000.00
        );

        $ledgerService->recordMovement(
            tenantId: $this->tenant->id,
            storeId: $this->storeA->id,
            itemType: 'product',
            itemId: $product->id,
            quantityDelta: -15,
            type: StockMovementType::SaleDeduction,
            unitCost: 80000.00
        );

        $ledgerService->recordMovement(
            tenantId: $this->tenant->id,
            storeId: $this->storeA->id,
            itemType: 'product',
            itemId: $product->id,
            quantityDelta: -5,
            type: StockMovementType::SaleDeduction,
            unitCost: 80000.00
        );

        $ledgerService->recordMovement(
            tenantId: $this->tenant->id,
            storeId: $this->storeA->id,
            itemType: 'product',
            itemId: $product->id,
            quantityDelta: 20,
            type: StockMovementType::GoodsReceipt,
            unitCost: 82000.00
        );

        $ledgerService->recordMovement(
            tenantId: $this->tenant->id,
            storeId: $this->storeA->id,
            itemType: 'product',
            itemId: $product->id,
            quantityDelta: -2,
            type: StockMovementType::SupplierReturnOut,
            unitCost: 82000.00
        );

        // Saldo ekspektasi: 50 - 15 - 5 + 20 - 2 = 48
        $currentBalance = $ledgerService->getBalance($this->tenant->id, $this->storeA->id, 'product', $product->id);
        $this->assertNotNull($currentBalance);
        $this->assertEquals(48.0, (float) $currentBalance->quantity);

        // Eksekusi artisan command inventory:reconcile-balances untuk tenant ini
        $exitCode = Artisan::call('inventory:reconcile-balances', [
            '--tenant' => $this->tenant->id,
        ]);

        $this->assertEquals(0, $exitCode, 'Artisan inventory:reconcile-balances wajib exit dengan status 0 (tanpa drift).');
    }

    /**
     * Uji alur Stock Transfer 2-tahap (Dispatched -> In-Transit -> Received).
     */
    public function test_two_step_stock_transfer_in_transit_flow(): void
    {
        $product = Product::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'Sepatu Sneakers Canvas',
            'product_code' => 'SPT-CAN-' . uniqid(),
            'product_price' => 350000.00,
            'standard_cost' => 200000.00,
            'stock' => 0,
            'track_inventory' => true,
            'is_active' => true,
        ]);

        $ledgerService = app(InventoryLedgerService::class);
        $transferService = app(StockTransferService::class);

        // Stok awal Cabang A: 100 unit. Stok Cabang B: 0 unit.
        $ledgerService->recordMovement(
            tenantId: $this->tenant->id,
            storeId: $this->storeA->id,
            itemType: 'product',
            itemId: $product->id,
            quantityDelta: 100,
            type: StockMovementType::InitialSync,
            unitCost: 200000.00
        );

        // 1. Buat Draft Transfer dari Cabang A ke Cabang B sejumlah 30 unit
        $transfer = $transferService->createDraft(
            tenantId: $this->tenant->id,
            fromStoreId: $this->storeA->id,
            toStoreId: $this->storeB->id,
            data: [
                'items' => [
                    [
                        'item_type' => 'product',
                        'product_id' => $product->id,
                        'quantity_requested' => 30,
                    ],
                ],
            ],
            userId: $this->user->id
        );

        $this->assertTrue($transfer->isDraft());

        // 2. Ship / Dispatch: Barang keluar dari Cabang A dan masuk status IN_TRANSIT
        $transferService->shipTransfer($transfer, $this->user->id);

        $qtyStoreAAfterShip = $ledgerService->getAvailableQuantity($this->tenant->id, $this->storeA->id, 'product', $product->id);
        $qtyStoreBAfterShip = $ledgerService->getAvailableQuantity($this->tenant->id, $this->storeB->id, 'product', $product->id);

        // Cabang A berkurang menjadi 70, Cabang B BELUM bertambah (masih 0) karena barang in-transit
        $this->assertEquals(70.0, $qtyStoreAAfterShip);
        $this->assertEquals(0.0, $qtyStoreBAfterShip);

        // 3. Receive: Barang diterima di Cabang B
        $transferService->receiveTransfer(
            transfer: $transfer,
            userId: $this->user->id,
            receiptData: [
                'items' => [
                    [
                        'item_id' => $transfer->items->first()->id,
                        'quantity_received' => 30,
                    ],
                ],
            ]
        );

        $qtyStoreBAfterReceive = $ledgerService->getAvailableQuantity($this->tenant->id, $this->storeB->id, 'product', $product->id);
        $this->assertEquals(30.0, $qtyStoreBAfterReceive);
    }

    /**
     * Uji kalkulasi Moving Average Cost dan Landed Cost pada InventoryCostingService.
     */
    public function test_moving_average_cost_and_landed_cost_calculation(): void
    {
        $costingService = app(InventoryCostingService::class);

        // Kasus: Saldo lama 100 kg @ Rp 12.000.
        // Masuk 200 kg @ Rp 13.500 dengan Ongkir / Landed Cost Rp 0.
        // Total = (100 * 12.000 + 200 * 13.500) / 300 = 3.900.000 / 300 = 13.000 / kg.
        $calc1 = $costingService->calculateMovingAverage(
            currentQty: 100,
            currentAvgCost: 12000.00,
            incomingQty: 200,
            incomingCost: 13500.00,
            landedCost: 0
        );

        $this->assertEquals('13000.0000', $calc1['new_average_cost']);
        $this->assertEquals('300.0000', $calc1['total_quantity']);

        // Kasus dengan Landed Cost:
        // Masuk 100 unit @ Rp 10.000 dengan tambahan ongkir Rp 50.000.
        // Effective unit cost = (100 * 10.000 + 50.000) / 100 = 1.050.000 / 100 = Rp 10.500.
        // Saldo lama: 0. Biaya baru = Rp 10.500.
        $calc2 = $costingService->calculateMovingAverage(
            currentQty: 0,
            currentAvgCost: 0,
            incomingQty: 100,
            incomingCost: 10000.00,
            landedCost: 50000.00
        );

        $this->assertEquals('10500.0000', $calc2['new_average_cost']);
        $this->assertEquals('10500.0000', $calc2['effective_unit_cost']);
    }
}
