<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\GoodsReceiptStatusEnum;
use App\Enums\StockMovementType;
use App\Models\GoodsReceipt;
use App\Models\ProductStockBalance;
use App\Models\StockMovement;
use App\Modules\Inventory\Services\GoodsReceiptService;
use App\Modules\Inventory\Services\InventoryLedgerService;
use App\Services\Context\TenantContext;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

class GoodsReceiptConcurrencyAndIdempotencyTest extends TestCase
{
    use RefreshDatabase;
    use TwoTenantFixture;

    protected GoodsReceiptService $service;

    protected InventoryLedgerService $ledgerService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        app(TenantContext::class)->runWithoutTenant(function () {
            $this->setupTwoTenants();
        });

        $this->ledgerService = app(InventoryLedgerService::class);
        $this->service = app(GoodsReceiptService::class);
    }

    public function test_post_receipt_is_idempotent_and_prevents_duplicate_ledger_postings(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $receipt = $this->service->createDraft(
            (int) $this->tenantA->id,
            (int) $this->storeA->id,
            [
                'items' => [
                    [
                        'item_type' => 'product',
                        'product_id' => (int) $this->productA->id,
                        'quantity_received' => '12.0000',
                        'unit_cost' => '15000.0000',
                    ],
                ],
            ],
            (int) $this->userAOwner->id
        );

        // First Post
        $firstResult = $this->service->postReceipt($receipt, (int) $this->userAOwner->id);
        $this->assertSame(GoodsReceiptStatusEnum::Posted, $firstResult->status);

        $prodBalance = ProductStockBalance::where('tenant_id', $this->tenantA->id)
            ->where('store_id', $this->storeA->id)
            ->where('product_id', $this->productA->id)
            ->firstOrFail();
        $this->assertSame(0, bccomp((string) $prodBalance->quantity, '12.0000', 4));

        $movementsCount = StockMovement::where('tenant_id', $this->tenantA->id)
            ->where('store_id', $this->storeA->id)
            ->where('product_id', $this->productA->id)
            ->count();
        $this->assertSame(1, $movementsCount);

        // Second Post (Idempotent call)
        $secondResult = $this->service->postReceipt($firstResult, (int) $this->userAOwner->id);
        $this->assertSame(GoodsReceiptStatusEnum::Posted, $secondResult->status);

        $prodBalanceFresh = ProductStockBalance::where('tenant_id', $this->tenantA->id)
            ->where('store_id', $this->storeA->id)
            ->where('product_id', $this->productA->id)
            ->firstOrFail();
        $this->assertSame(0, bccomp((string) $prodBalanceFresh->quantity, '12.0000', 4));

        $movementsCountAfter = StockMovement::where('tenant_id', $this->tenantA->id)
            ->where('store_id', $this->storeA->id)
            ->where('product_id', $this->productA->id)
            ->count();
        $this->assertSame(1, $movementsCountAfter);
    }

    public function test_create_draft_is_idempotent_with_same_idempotency_key(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $idempotencyKey = 'unique-grn-idempotency-key-001';

        $draft1 = $this->service->createDraft(
            (int) $this->tenantA->id,
            (int) $this->storeA->id,
            [
                'idempotency_key' => $idempotencyKey,
                'items' => [
                    [
                        'item_type' => 'product',
                        'product_id' => (int) $this->productA->id,
                        'quantity_received' => '5.0000',
                        'unit_cost' => '10000.0000',
                    ],
                ],
            ],
            (int) $this->userAOwner->id
        );

        $draft2 = $this->service->createDraft(
            (int) $this->tenantA->id,
            (int) $this->storeA->id,
            [
                'idempotency_key' => $idempotencyKey,
                'items' => [
                    [
                        'item_type' => 'product',
                        'product_id' => (int) $this->productA->id,
                        'quantity_received' => '5.0000',
                        'unit_cost' => '10000.0000',
                    ],
                ],
            ],
            (int) $this->userAOwner->id
        );

        $this->assertSame($draft1->id, $draft2->id);
        $this->assertSame(1, GoodsReceipt::where('tenant_id', $this->tenantA->id)->count());
    }

    public function test_reversal_is_idempotent_and_prevents_duplicate_reversals(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $receipt = $this->service->createDraft(
            (int) $this->tenantA->id,
            (int) $this->storeA->id,
            [
                'items' => [
                    [
                        'item_type' => 'product',
                        'product_id' => (int) $this->productA->id,
                        'quantity_received' => '7.0000',
                        'unit_cost' => '15000.0000',
                    ],
                ],
            ],
            (int) $this->userAOwner->id
        );

        $this->service->postReceipt($receipt, (int) $this->userAOwner->id);

        // First Reversal
        $rev1 = $this->service->reverseReceipt($receipt, (int) $this->userAOwner->id, 'Alasan pembalikkan pertama');
        $this->assertSame(GoodsReceiptStatusEnum::Reversed, $rev1->status);

        $reversalMovements = StockMovement::where('tenant_id', $this->tenantA->id)
            ->where('store_id', $this->storeA->id)
            ->where('product_id', $this->productA->id)
            ->where('type', StockMovementType::GoodsReceiptReversal->value)
            ->count();
        $this->assertSame(1, $reversalMovements);

        // Second Reversal (Idempotent call)
        $rev2 = $this->service->reverseReceipt($rev1, (int) $this->userAOwner->id, 'Panggilan kedua');
        $this->assertSame(GoodsReceiptStatusEnum::Reversed, $rev2->status);

        $reversalMovementsAfter = StockMovement::where('tenant_id', $this->tenantA->id)
            ->where('store_id', $this->storeA->id)
            ->where('product_id', $this->productA->id)
            ->where('type', StockMovementType::GoodsReceiptReversal->value)
            ->count();
        $this->assertSame(1, $reversalMovementsAfter);
    }

    public function test_reversal_is_strictly_rejected_if_it_causes_negative_branch_stock(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        // 1. Terima 10 unit produk A
        $receipt = $this->service->createDraft(
            (int) $this->tenantA->id,
            (int) $this->storeA->id,
            [
                'items' => [
                    [
                        'item_type' => 'product',
                        'product_id' => (int) $this->productA->id,
                        'quantity_received' => '10.0000',
                        'unit_cost' => '20000.0000',
                    ],
                ],
            ],
            (int) $this->userAOwner->id
        );

        $this->service->postReceipt($receipt, (int) $this->userAOwner->id);

        // 2. Simulasikan penjualan / pengurangan stok cabang sebanyak 6 unit (sisa tinggal 4 unit)
        $this->ledgerService->recordMovement(
            (int) $this->tenantA->id,
            (int) $this->storeA->id,
            'product',
            (int) $this->productA->id,
            '-6.0000',
            StockMovementType::SaleDeduction,
            '20000.0000',
            'Penjualan kasir POS'
        );

        $balanceBefore = $this->ledgerService->getBalance((int) $this->tenantA->id, (int) $this->storeA->id, 'product', (int) $this->productA->id);
        $this->assertSame(0, bccomp((string) $balanceBefore->quantity, '4.0000', 4));

        // 3. Upaya Reversal GRN (mengurangi 10 unit) HARUS DITOLAK oleh OD-05
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('OD-05');

        try {
            $this->service->reverseReceipt($receipt, (int) $this->userAOwner->id, 'Mencoba membalikkan receipt padahal stok sudah terpakai');
        } finally {
            // Verifikasi stok tetap 4 unit dan status tetap POSTED
            $balanceAfter = $this->ledgerService->getBalance((int) $this->tenantA->id, (int) $this->storeA->id, 'product', (int) $this->productA->id);
            $this->assertSame(0, bccomp((string) $balanceAfter->quantity, '4.0000', 4));

            $receiptFresh = GoodsReceipt::find($receipt->id);
            $this->assertSame(GoodsReceiptStatusEnum::Posted, $receiptFresh->status);
        }
    }
}
