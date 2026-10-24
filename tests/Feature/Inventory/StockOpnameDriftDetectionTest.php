<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\StockMovementType;
use App\Enums\StockOpnameDriftResolutionEnum;
use App\Enums\StockOpnameStatusEnum;
use App\Modules\Inventory\Exceptions\StockOpnameDriftException;
use App\Modules\Inventory\Services\InventoryLedgerService;
use App\Modules\Inventory\Services\StockOpnameService;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

class StockOpnameDriftDetectionTest extends TestCase
{
    use RefreshDatabase;
    use TwoTenantFixture;

    protected StockOpnameService $service;

    protected InventoryLedgerService $ledgerService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        app(TenantContext::class)->runWithoutTenant(function () {
            $this->setupTwoTenants();
        });

        $this->ledgerService = app(InventoryLedgerService::class);
        $this->service = app(StockOpnameService::class);
    }

    /**
     * Uji deteksi pergerakan stok sela (POS Sale) di antara waktu snapshot dan approval.
     * Memastikan Item-Level Drift Gate (OD-04) memblokir auto-approval dan menolak penimpaan buta.
     */
    public function test_concurrent_pos_sale_triggers_drift_gate_and_blocks_auto_approval(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        // 1. Inisialisasi Saldo Awal: 50 unit
        $this->ledgerService->recordMovement(
            tenantId: (int) $this->tenantA->id,
            storeId: (int) $this->storeA->id,
            itemType: 'product',
            itemId: (int) $this->productA->id,
            quantityDelta: '50.0000',
            type: StockMovementType::InitialSync,
            unitCost: '10000.0000',
            userId: (int) $this->userAOwner->id
        );

        $initialBalance = $this->ledgerService->getBalance(
            (int) $this->tenantA->id,
            (int) $this->storeA->id,
            'product',
            (int) $this->productA->id
        );
        $this->assertSame(0, bccomp((string) $initialBalance->quantity, '50.0000', 4));

        // 2. Petugas Gudang Memulai Sesi Stock Opname
        $opname = $this->service->createDraft(
            tenantId: (int) $this->tenantA->id,
            storeId: (int) $this->storeA->id,
            data: [
                'items' => [
                    ['item_type' => 'product', 'product_id' => (int) $this->productA->id],
                ],
            ],
            userId: (int) $this->userAOwner->id
        );

        $opname = $this->service->startCounting($opname, (int) $this->userAOwner->id);
        $item = $opname->items[0]->fresh();

        // Verifikasi Snapshot Sequence Pinning (OD-04)
        $this->assertSame(0, bccomp((string) $item->snapshot_on_hand, '50.0000', 4));
        $this->assertSame((int) $initialBalance->last_movement_id, (int) $item->snapshot_last_movement_id);

        // 3. Petugas menginput hasil hitung fisik: 48 unit (hilang 2 unit)
        $this->service->recordCounts($opname, [$item->id => '48.0000'], (int) $this->userAOwner->id);
        $opname = $this->service->submitForApproval($opname, (int) $this->userAOwner->id);
        $this->assertSame(StockOpnameStatusEnum::PendingApproval, $opname->status);

        // 4. KONKUREN: Terjadi Transaksi Penjualan Kasir POS di sela waktu hitung & approval!
        // Kasir menjual 5 unit Product A
        $this->ledgerService->recordMovement(
            tenantId: (int) $this->tenantA->id,
            storeId: (int) $this->storeA->id,
            itemType: 'product',
            itemId: (int) $this->productA->id,
            quantityDelta: '-5.0000',
            type: StockMovementType::SaleDeduction,
            reason: 'Penjualan POS Kasir #ORD-999',
            userId: (int) $this->userAOwner->id
        );

        // Saldo di database sekarang berkurang menjadi 45 unit
        $midBalance = $this->ledgerService->getBalance(
            (int) $this->tenantA->id,
            (int) $this->storeA->id,
            'product',
            (int) $this->productA->id
        );
        $this->assertSame(0, bccomp((string) $midBalance->quantity, '45.0000', 4));
        $this->assertNotSame((int) $item->snapshot_last_movement_id, (int) $midBalance->last_movement_id);

        // 5. Manajer mencoba menyetujui opname tanpa resolusi drift
        // SISTEM WAJIB MENOLAK dan melempar StockOpnameDriftException!
        try {
            $this->service->approveOpname($opname, (int) $this->userAOwner->id);
            $this->fail('Harusnya melempar StockOpnameDriftException karena terjadi mutasi sela penjualan kasir!');
        } catch (StockOpnameDriftException $e) {
            $this->assertStringContainsString('DRIFT_DETECTED', $e->getMessage());
            $drifted = $e->getDriftedItems();
            $this->assertCount(1, $drifted);
            $this->assertSame((int) $item->id, $drifted[0]['item_id']);
            $this->assertSame(0, bccomp($drifted[0]['snapshot_on_hand'], '50.0000', 4));
            $this->assertSame(0, bccomp($drifted[0]['current_on_hand'], '45.0000', 4));
            $this->assertSame(0, bccomp($drifted[0]['drift_delta'], '-5.0000', 4));
        }

        // Pastikan opname masih tetap PENDING_APPROVAL dan saldo riil tetap 45 unit (tidak tertimpa!)
        $this->assertSame(StockOpnameStatusEnum::PendingApproval, $opname->fresh()->status);
        $unchangedBalance = $this->ledgerService->getBalance(
            (int) $this->tenantA->id,
            (int) $this->storeA->id,
            'product',
            (int) $this->productA->id
        );
        $this->assertSame(0, bccomp((string) $unchangedBalance->quantity, '45.0000', 4));
    }

    /**
     * Uji resolusi drift dengan RE_SNAPSHOT (Hitung Ulang).
     */
    public function test_drift_resolution_re_snapshot_refreshes_baseline_and_resets_opname_to_counting(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        // 1. Saldo 30 unit
        $this->ledgerService->recordMovement(
            tenantId: (int) $this->tenantA->id,
            storeId: (int) $this->storeA->id,
            itemType: 'product',
            itemId: (int) $this->productA->id,
            quantityDelta: '30.0000',
            type: StockMovementType::InitialSync,
            userId: (int) $this->userAOwner->id
        );

        $opname = $this->service->createDraft(
            tenantId: (int) $this->tenantA->id,
            storeId: (int) $this->storeA->id,
            data: [
                'items' => [
                    ['item_type' => 'product', 'product_id' => (int) $this->productA->id],
                ],
            ],
            userId: (int) $this->userAOwner->id
        );

        $opname = $this->service->startCounting($opname, (int) $this->userAOwner->id);
        $item = $opname->items[0]->fresh();

        $this->service->recordCounts($opname, [$item->id => '28.0000'], (int) $this->userAOwner->id);
        $opname = $this->service->submitForApproval($opname, (int) $this->userAOwner->id);

        // Penjualan sela: -4 unit
        $this->ledgerService->recordMovement(
            tenantId: (int) $this->tenantA->id,
            storeId: (int) $this->storeA->id,
            itemType: 'product',
            itemId: (int) $this->productA->id,
            quantityDelta: '-4.0000',
            type: StockMovementType::SaleDeduction,
            userId: (int) $this->userAOwner->id
        );

        // Resolusi RE_SNAPSHOT
        $resolvedItem = $this->service->resolveDrift(
            $opname,
            (int) $item->id,
            StockOpnameDriftResolutionEnum::ReSnapshot,
            (int) $this->userAOwner->id
        );

        // Assert item baseline diperbarui ke saldo terkini (26 unit)
        $this->assertSame(0, bccomp((string) $resolvedItem->snapshot_on_hand, '26.0000', 4));
        $this->assertNull($resolvedItem->counted_quantity);
        $this->assertNull($resolvedItem->variance_quantity);

        // Status opname harus kembali ke COUNTING agar dihitung ulang
        $this->assertSame(StockOpnameStatusEnum::Counting, $opname->fresh()->status);
    }

    /**
     * Uji resolusi drift dengan REBASE_OVERRIDE.
     * Manajer mengesampingkan drift dan menerima hasil hitung fisik dengan merebase selisih.
     */
    public function test_drift_resolution_rebase_override_recalculates_variance_and_successfully_approves(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        // Saldo awal 100 unit
        $this->ledgerService->recordMovement(
            tenantId: (int) $this->tenantA->id,
            storeId: (int) $this->storeA->id,
            itemType: 'product',
            itemId: (int) $this->productA->id,
            quantityDelta: '100.0000',
            type: StockMovementType::InitialSync,
            unitCost: '10000.0000',
            userId: (int) $this->userAOwner->id
        );

        $opname = $this->service->createDraft(
            tenantId: (int) $this->tenantA->id,
            storeId: (int) $this->storeA->id,
            data: [
                'items' => [
                    ['item_type' => 'product', 'product_id' => (int) $this->productA->id],
                ],
            ],
            userId: (int) $this->userAOwner->id
        );

        $opname = $this->service->startCounting($opname, (int) $this->userAOwner->id);
        $item = $opname->items[0]->fresh();

        // Fisik dihitung 95 unit (sebelum penjualan sela)
        $this->service->recordCounts($opname, [$item->id => '95.0000'], (int) $this->userAOwner->id);
        $opname = $this->service->submitForApproval($opname, (int) $this->userAOwner->id);

        // Penjualan sela 10 unit di kasir POS: saldo buku sekarang 90
        $this->ledgerService->recordMovement(
            tenantId: (int) $this->tenantA->id,
            storeId: (int) $this->storeA->id,
            itemType: 'product',
            itemId: (int) $this->productA->id,
            quantityDelta: '-10.0000',
            type: StockMovementType::SaleDeduction,
            userId: (int) $this->userAOwner->id
        );

        // Manajer memeriksa dan menyatakan bahwa hasil hitung fisik 95 unit adalah sebelum kasir menjual 10 unit,
        // atau jika fisik 95 unit adalah stok fisik nyata terkini yang harus di-override.
        // Manajer memilih REBASE_OVERRIDE:
        $this->service->resolveDrift(
            $opname,
            (int) $item->id,
            StockOpnameDriftResolutionEnum::RebaseOverride,
            (int) $this->userAOwner->id
        );

        // Sekarang approveOpname berhasil disetujui!
        $approvedOpname = $this->service->approveOpname($opname, (int) $this->userAOwner->id);

        $this->assertSame(StockOpnameStatusEnum::Approved, $approvedOpname->status);

        // Verifikasi saldo akhir di database:
        // Saldo terkini adalah 90. Dengan fisik 95, rebase variance = 95 - 90 = +5.
        // Saldo akhir menjadi 90 + (+5) = 95.0000!
        $finalBalance = $this->ledgerService->getBalance(
            (int) $this->tenantA->id,
            (int) $this->storeA->id,
            'product',
            (int) $this->productA->id
        );
        $this->assertSame(0, bccomp((string) $finalBalance->quantity, '95.0000', 4));
    }
}
