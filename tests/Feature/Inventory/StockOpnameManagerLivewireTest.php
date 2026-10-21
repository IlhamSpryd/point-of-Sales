<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\StockMovementType;
use App\Enums\StockOpnameStatusEnum;
use App\Livewire\Inventory\StockOpnameManager;
use App\Models\StockOpname;
use App\Modules\Inventory\Services\InventoryLedgerService;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

class StockOpnameManagerLivewireTest extends TestCase
{
    use RefreshDatabase;
    use TwoTenantFixture;

    protected InventoryLedgerService $ledgerService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        app(TenantContext::class)->runWithoutTenant(function () {
            $this->setupTwoTenants();
        });

        $this->ledgerService = app(InventoryLedgerService::class);
    }

    public function test_can_render_stock_opname_manager_page(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $this->actingAs($this->userAOwner)
            ->withSession(['active_store_id' => $this->storeA->id])
            ->get(route('inventory.opnames'))
            ->assertOk()
            ->assertSee('Stock Opname');
    }

    public function test_can_create_start_counting_record_and_approve_via_livewire(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        // Saldo awal 15
        $this->ledgerService->recordMovement(
            tenantId: (int) $this->tenantA->id,
            storeId: (int) $this->storeA->id,
            itemType: 'product',
            itemId: (int) $this->productA->id,
            quantityDelta: '15.0000',
            type: StockMovementType::InitialSync,
            userId: (int) $this->userAOwner->id
        );

        $this->actingAs($this->userAOwner);

        // 1. Create Draft via Livewire
        Livewire::test(StockOpnameManager::class)
            ->call('openCreateModal')
            ->assertSet('showFormModal', true)
            ->set('all_items', true)
            ->set('notes', 'Sesi opname bulanan via Livewire')
            ->call('saveOpname')
            ->assertHasNoErrors()
            ->assertSet('showFormModal', false);

        $opname = StockOpname::where('tenant_id', $this->tenantA->id)->latest('id')->firstOrFail();
        $this->assertSame(StockOpnameStatusEnum::Draft, $opname->status);

        // 2. Start Counting via Livewire
        Livewire::test(StockOpnameManager::class)
            ->call('startCounting', $opname->id)
            ->assertHasNoErrors();

        $opname->refresh();
        $this->assertSame(StockOpnameStatusEnum::Counting, $opname->status);

        // 3. Open count modal, input counted quantity, and save
        $firstItem = $opname->items()->firstOrFail();

        Livewire::test(StockOpnameManager::class)
            ->call('openCountModal', $opname->id)
            ->assertSet('showCountModal', true)
            ->set("countedQuantities.{$firstItem->id}", '18.0000')
            ->call('saveCounts')
            ->assertHasNoErrors()
            ->assertSet('showCountModal', false);

        // 4. Submit for Approval via Livewire
        Livewire::test(StockOpnameManager::class)
            ->call('submitForApproval', $opname->id)
            ->assertHasNoErrors();

        $opname->refresh();
        $this->assertSame(StockOpnameStatusEnum::PendingApproval, $opname->status);

        // 5. Approve via Livewire
        Livewire::test(StockOpnameManager::class)
            ->call('approveOpname', $opname->id)
            ->assertHasNoErrors();

        $opname->refresh();
        $this->assertSame(StockOpnameStatusEnum::Approved, $opname->status);
    }

    public function test_livewire_displays_drift_modal_when_drift_detected_on_approval(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $this->ledgerService->recordMovement(
            tenantId: (int) $this->tenantA->id,
            storeId: (int) $this->storeA->id,
            itemType: 'product',
            itemId: (int) $this->productA->id,
            quantityDelta: '20.0000',
            type: StockMovementType::InitialSync,
            userId: (int) $this->userAOwner->id
        );

        $this->actingAs($this->userAOwner);

        $component = Livewire::test(StockOpnameManager::class)
            ->call('openCreateModal')
            ->set('all_items', true)
            ->call('saveOpname');

        $opname = StockOpname::where('tenant_id', $this->tenantA->id)->latest('id')->firstOrFail();

        $component->call('startCounting', $opname->id);
        $item = $opname->items()->firstOrFail();

        $component->call('openCountModal', $opname->id)
            ->set("countedQuantities.{$item->id}", '20.0000')
            ->call('saveCounts')
            ->call('submitForApproval', $opname->id);

        // Terjadi penjualan sela POS di kasir
        $this->ledgerService->recordMovement(
            tenantId: (int) $this->tenantA->id,
            storeId: (int) $this->storeA->id,
            itemType: 'product',
            itemId: (int) $this->productA->id,
            quantityDelta: '-3.0000',
            type: StockMovementType::SaleDeduction,
            userId: (int) $this->userAOwner->id
        );

        // Approval dipanggil -> harus menampilkan Drift Modal (showDriftModal = true)
        $component->call('approveOpname', $opname->id)
            ->assertSet('showDriftModal', true)
            ->assertCount('driftedItems', 1);
    }
}
