<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\GoodsReceiptStatusEnum;
use App\Livewire\Inventory\GoodsReceiptManager;
use App\Models\GoodsReceipt;
use App\Models\Ingredient;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

class GoodsReceiptManagerLivewireTest extends TestCase
{
    use RefreshDatabase;
    use TwoTenantFixture;

    protected Ingredient $ingredientA;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        app(TenantContext::class)->runWithoutTenant(function () {
            $this->setupTwoTenants();

            $this->ingredientA = Ingredient::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Biji Kopi Robusta',
                'sku' => 'ING-ROB-01',
                'unit' => 'kg',
                'cost_per_unit' => '40000.0000',
            ]);
        });
    }

    public function test_can_render_goods_receipt_manager_page(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $this->actingAs($this->userAOwner)
            ->withSession(['active_store_id' => $this->storeA->id])
            ->get(route('inventory.goods-receipts'))
            ->assertOk()
            ->assertSee('Penerimaan Barang (Goods Receipt)');
    }

    public function test_can_create_draft_and_post_via_livewire(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $this->actingAs($this->userAOwner);

        Livewire::test(GoodsReceiptManager::class)
            ->call('openCreateModal')
            ->assertSet('showFormModal', true)
            ->set('supplier_reference', 'Supplier Jaya Abadi')
            ->set('purchase_order_reference', 'PO-999')
            ->set('items.0.item_type', 'product')
            ->set('items.0.product_id', $this->productA->id)
            ->set('items.0.quantity_received', '5.0000')
            ->set('items.0.unit_cost', '12000.0000')
            ->set('items.0.batch_number', 'BATCH-LW-01')
            ->call('saveDraft')
            ->assertHasNoErrors()
            ->assertSet('showFormModal', false);

        $receipt = GoodsReceipt::where('tenant_id', $this->tenantA->id)
            ->where('supplier_reference', 'Supplier Jaya Abadi')
            ->firstOrFail();

        $this->assertSame(GoodsReceiptStatusEnum::Draft, $receipt->status);
        $this->assertSame('60000.0000', $receipt->calculateTotalValue());

        // Test Posting via Livewire
        Livewire::test(GoodsReceiptManager::class)
            ->call('post', $receipt->id)
            ->assertHasNoErrors();

        $receipt->refresh();
        $this->assertSame(GoodsReceiptStatusEnum::Posted, $receipt->status);
    }
}
