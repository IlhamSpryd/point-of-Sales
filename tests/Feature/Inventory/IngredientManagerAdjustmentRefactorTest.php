<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\StockAdjustmentReasonEnum;
use App\Enums\StockAdjustmentStatusEnum;
use App\Livewire\Inventory\IngredientManager;
use App\Models\Ingredient;
use App\Models\StockAdjustment;
use App\Modules\Inventory\Services\InventoryLedgerService;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

class IngredientManagerAdjustmentRefactorTest extends TestCase
{
    use RefreshDatabase;
    use TwoTenantFixture;

    protected Ingredient $ingredient;

    protected InventoryLedgerService $ledgerService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        app(TenantContext::class)->runWithoutTenant(function () {
            $this->setupTwoTenants();

            $this->ingredient = Ingredient::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Sirup Karamel',
                'sku' => 'ING-SYRUP-01',
                'unit' => 'botol',
                'cost_per_unit' => '35000.0000',
            ]);
        });

        $this->ledgerService = app(InventoryLedgerService::class);
    }

    public function test_waste_adjustment_creates_stock_adjustment_document_and_reduces_balance(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $this->actingAs($this->userAOwner);

        // 1. Berikan saldo awal 10 botol via purchase_receipt
        Livewire::test(IngredientManager::class)
            ->call('adjustStock', $this->ingredient->id)
            ->set('adjustType', 'purchase_receipt')
            ->set('adjustQuantity', 10)
            ->set('adjustReason', 'Penerimaan stok awal')
            ->call('saveAdjustment')
            ->assertHasNoErrors()
            ->assertSet('showAdjustModal', false);

        $balanceAfterIn = $this->ledgerService->getBalance((int) $this->tenantA->id, (int) $this->storeA->id, 'ingredient', (int) $this->ingredient->id);
        $this->assertSame(0, bccomp((string) $balanceAfterIn->quantity, '10.0000', 4));

        // 2. Lakukan penyesuaian waste sebesar 3 botol
        Livewire::test(IngredientManager::class)
            ->call('adjustStock', $this->ingredient->id)
            ->set('adjustType', 'waste')
            ->set('adjustQuantity', 3)
            ->set('adjustReason', 'Botol pecah di gudang')
            ->call('saveAdjustment')
            ->assertHasNoErrors();

        // 3. Verifikasi saldo berkurang menjadi 7
        $balanceAfterWaste = $this->ledgerService->getBalance((int) $this->tenantA->id, (int) $this->storeA->id, 'ingredient', (int) $this->ingredient->id);
        $this->assertSame(0, bccomp((string) $balanceAfterWaste->quantity, '7.0000', 4));

        // 4. Verifikasi dokumen StockAdjustment tercatat dengan reason PRODUCTION_WASTE dan status POSTED
        $adjustment = StockAdjustment::where('tenant_id', $this->tenantA->id)
            ->where('reason_code', StockAdjustmentReasonEnum::ProductionWaste)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(StockAdjustmentStatusEnum::Posted, $adjustment->status);
        $this->assertCount(1, $adjustment->items);
        $this->assertSame('OUT', $adjustment->items->first()->direction);
        $this->assertSame(0, bccomp((string) $adjustment->items->first()->quantity, '3.0000', 4));

        // 5. Verifikasi activity log audit trail tercatat
        $this->assertDatabaseHas('activity_logs', [
            'tenant_id' => $this->tenantA->id,
            'action' => 'inventory.stock_adjustment.posted',
            'subject_id' => $adjustment->id,
        ]);
    }

    public function test_adjustment_causing_negative_stock_is_rejected_with_validation_error(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $this->actingAs($this->userAOwner);

        // Saldo saat ini 0, coba lakukan pengurangan waste 2 botol
        Livewire::test(IngredientManager::class)
            ->call('adjustStock', $this->ingredient->id)
            ->set('adjustType', 'waste')
            ->set('adjustQuantity', 2)
            ->set('adjustReason', 'Mencoba waste saat saldo 0')
            ->call('saveAdjustment')
            ->assertHasErrors(['adjustQuantity']);

        // Saldo tetap 0
        $balance = $this->ledgerService->getBalance((int) $this->tenantA->id, (int) $this->storeA->id, 'ingredient', (int) $this->ingredient->id);
        $this->assertSame(0, bccomp((string) ($balance->quantity ?? 0), '0.0000', 4));
    }
}
