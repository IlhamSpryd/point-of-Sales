<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\StockAdjustmentReasonEnum;
use App\Enums\StockAdjustmentStatusEnum;
use App\Livewire\Inventory\StockAdjustmentManager;
use App\Models\StockAdjustment;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

class StockAdjustmentManagerLivewireTest extends TestCase
{
    use RefreshDatabase;
    use TwoTenantFixture;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        app(TenantContext::class)->runWithoutTenant(function () {
            $this->setupTwoTenants();
        });
    }

    public function test_can_render_stock_adjustment_manager_page(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $this->actingAs($this->userAOwner)
            ->withSession(['active_store_id' => $this->storeA->id])
            ->get(route('inventory.adjustments'))
            ->assertOk()
            ->assertSee('Penyesuaian Stok (Stock Adjustment)');
    }

    public function test_can_create_draft_and_post_via_livewire(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $this->actingAs($this->userAOwner);

        Livewire::test(StockAdjustmentManager::class)
            ->call('openCreateModal')
            ->assertSet('showFormModal', true)
            ->set('reason_code', StockAdjustmentReasonEnum::OpeningStock->value)
            ->set('items.0.item_type', 'product')
            ->set('items.0.product_id', $this->productA->id)
            ->set('items.0.direction', 'IN')
            ->set('items.0.quantity', '14.0000')
            ->set('items.0.unit_cost_snapshot', '25000.0000')
            ->set('notes', 'Penyesuaian stok awal tahun')
            ->call('saveDraft')
            ->assertHasNoErrors()
            ->assertSet('showFormModal', false);

        $adjustment = StockAdjustment::where('tenant_id', $this->tenantA->id)
            ->where('reason_code', StockAdjustmentReasonEnum::OpeningStock)
            ->firstOrFail();

        $this->assertSame(StockAdjustmentStatusEnum::Draft, $adjustment->status);

        // Posting via Livewire
        Livewire::test(StockAdjustmentManager::class)
            ->call('post', $adjustment->id)
            ->assertHasNoErrors();

        $adjustment->refresh();
        $this->assertSame(StockAdjustmentStatusEnum::Posted, $adjustment->status);
    }
}
