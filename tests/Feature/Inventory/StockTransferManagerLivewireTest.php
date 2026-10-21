<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\StockMovementType;
use App\Enums\StockTransferStatusEnum;
use App\Livewire\Inventory\StockTransferManager;
use App\Models\StockTransfer;
use App\Modules\Inventory\Services\InventoryLedgerService;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

class StockTransferManagerLivewireTest extends TestCase
{
    use RefreshDatabase;
    use TwoTenantFixture;

    protected InventoryLedgerService $ledgerService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        app(TenantContext::class)->runWithoutTenant(function () {
            $this->setupTwoTenants();

            // Berikan userAOwner akses ke storeA2 agar dapat menerima transfer di storeA2
            $this->userAOwner->stores()->attach($this->storeA2->id, ['tenant_id' => $this->tenantA->id]);
        });

        $this->ledgerService = app(InventoryLedgerService::class);
    }

    public function test_can_render_stock_transfer_manager_page(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $this->actingAs($this->userAOwner)
            ->withSession(['active_store_id' => $this->storeA->id])
            ->get(route('inventory.transfers'))
            ->assertOk()
            ->assertSee('Transfer Stok Antarcabang');
    }

    public function test_can_create_draft_transfer_via_livewire(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        Livewire::actingAs($this->userAOwner)
            ->test(StockTransferManager::class)
            ->call('openCreateModal')
            ->set('to_store_id', (int) $this->storeA2->id)
            ->set('notes', 'Transfer pengadaan mingguan')
            ->set('items', [
                [
                    'item_type' => 'product',
                    'product_id' => (int) $this->productA->id,
                    'ingredient_id' => null,
                    'quantity_requested' => '10.0000',
                    'notes' => 'Varian standar',
                ],
            ])
            ->call('saveTransfer')
            ->assertHasNoErrors()
            ->assertSet('showFormModal', false);

        $transfer = StockTransfer::withoutTenantScope()
            ->where('tenant_id', $this->tenantA->id)
            ->where('from_store_id', $this->storeA->id)
            ->where('to_store_id', $this->storeA2->id)
            ->first();

        $this->assertNotNull($transfer);
        $this->assertSame(StockTransferStatusEnum::Draft, $transfer->status);
        $this->assertCount(1, $transfer->items);
    }

    public function test_can_ship_and_receive_transfer_via_livewire(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        // Saldo awal 20 unit di storeA
        $this->ledgerService->recordMovement(
            tenantId: (int) $this->tenantA->id,
            storeId: (int) $this->storeA->id,
            itemType: 'product',
            itemId: (int) $this->productA->id,
            quantityDelta: '20.0000',
            type: StockMovementType::InitialSync,
            unitCost: '15000.0000',
            userId: (int) $this->userAOwner->id
        );

        // 1. Buat Draft via Livewire
        $testComponent = Livewire::actingAs($this->userAOwner)
            ->test(StockTransferManager::class)
            ->call('openCreateModal')
            ->set('to_store_id', (int) $this->storeA2->id)
            ->set('notes', 'Kirim ke cabang 2')
            ->set('items', [
                [
                    'item_type' => 'product',
                    'product_id' => (int) $this->productA->id,
                    'ingredient_id' => null,
                    'quantity_requested' => '5.0000',
                ],
            ])
            ->call('saveTransfer');

        $transfer = StockTransfer::withoutTenantScope()
            ->where('tenant_id', $this->tenantA->id)
            ->latest('id')
            ->firstOrFail();

        // 2. Dispatch / Ship via Livewire
        $testComponent->call('shipTransfer', (int) $transfer->id)
            ->assertHasNoErrors();

        $transfer->refresh();
        $this->assertSame(StockTransferStatusEnum::InTransit, $transfer->status);

        // Saldo cabang asal berkurang menjadi 15
        $balA = $this->ledgerService->getBalance((int) $this->tenantA->id, (int) $this->storeA->id, 'product', (int) $this->productA->id);
        $this->assertSame(0, bccomp((string) $balA->quantity, '15.0000', 4));

        // 3. Receive via Livewire
        $item = $transfer->items()->first();

        // Switch active store context to storeA2 for receiving
        $context->setStoreId($this->storeA2->id);

        Livewire::actingAs($this->userAOwner)
            ->test(StockTransferManager::class)
            ->call('openReceiveModal', (int) $transfer->id)
            ->set("receivedQuantities.{$item->id}", '5.0000')
            ->set("discrepancyResolutions.{$item->id}", 'NONE')
            ->call('submitReceive')
            ->assertHasNoErrors()
            ->assertSet('showReceiveModal', false);

        $transfer->refresh();
        $this->assertSame(StockTransferStatusEnum::Received, $transfer->status);

        // Saldo cabang tujuan bertambah 5
        $balA2 = $this->ledgerService->getBalance((int) $this->tenantA->id, (int) $this->storeA2->id, 'product', (int) $this->productA->id);
        $this->assertSame(0, bccomp((string) $balA2->quantity, '5.0000', 4));
    }

    public function test_can_cancel_draft_transfer_via_livewire(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        Livewire::actingAs($this->userAOwner)
            ->test(StockTransferManager::class)
            ->call('openCreateModal')
            ->set('to_store_id', (int) $this->storeA2->id)
            ->set('items', [
                [
                    'item_type' => 'product',
                    'product_id' => (int) $this->productA->id,
                    'ingredient_id' => null,
                    'quantity_requested' => '3.0000',
                ],
            ])
            ->call('saveTransfer');

        $transfer = StockTransfer::withoutTenantScope()
            ->where('tenant_id', $this->tenantA->id)
            ->latest('id')
            ->firstOrFail();

        Livewire::actingAs($this->userAOwner)
            ->test(StockTransferManager::class)
            ->call('cancelTransfer', (int) $transfer->id)
            ->assertHasNoErrors();

        $transfer->refresh();
        $this->assertSame(StockTransferStatusEnum::Cancelled, $transfer->status);
    }
}
