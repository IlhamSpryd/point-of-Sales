<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Livewire\Inventory\ModifierManager;
use App\Models\Ingredient;
use App\Models\ModifierGroup;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

class ModifierManagerTenantIsolationTest extends TestCase
{
    use RefreshDatabase;
    use TwoTenantFixture;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->runWithoutTenant(function () {
            $this->setupTwoTenants();
        });
    }

    public function test_unauthorized_user_cannot_access_modifier_manager(): void
    {
        Livewire::actingAs($this->userBBarista)
            ->test(ModifierManager::class)
            ->assertForbidden();
    }

    public function test_manager_cannot_sync_cross_tenant_ingredient_in_modifier(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $groupA = ModifierGroup::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Topping Group A',
            'selection_type' => 'single',
            'is_required' => false,
        ]);

        $ingredientB = $context->runAs($this->tenantB->id, $this->storeB->id, function () {
            return Ingredient::create([
                'tenant_id' => $this->tenantB->id,
                'name' => 'Secret Syrup Tenant B',
                'unit' => 'ml',
                'cost_per_unit' => 500,
                'reorder_level' => 10,
                'is_active' => true,
            ]);
        });

        $this->actingAs($this->userAOwner);

        Livewire::test(ModifierManager::class)
            ->call('createModifier', $groupA->id)
            ->set('modifierForm.name', 'Extra Syrup')
            ->set('modifierForm.extra_price', 5000)
            ->set('modifierForm.is_default', false)
            ->set('modifierForm.ingredients', [
                ['id' => $ingredientB->id, 'quantity' => 20],
            ])
            ->call('saveModifier')
            ->assertHasErrors(['modifierForm.ingredients.0.id']);
    }

    public function test_save_modifier_syncs_bom_with_correct_tenant_id_in_pivot(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $groupA = ModifierGroup::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Milk Options',
            'selection_type' => 'single',
            'is_required' => false,
        ]);

        $ingredientA = Ingredient::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Oat Milk',
            'unit' => 'ml',
            'cost_per_unit' => 200,
            'reorder_level' => 5,
            'is_active' => true,
        ]);

        $this->actingAs($this->userAOwner);

        Livewire::test(ModifierManager::class)
            ->call('createModifier', $groupA->id)
            ->set('modifierForm.name', 'Oat Milk Sub')
            ->set('modifierForm.extra_price', 6000)
            ->set('modifierForm.is_default', false)
            ->set('modifierForm.ingredients', [
                ['id' => $ingredientA->id, 'quantity' => 150],
            ])
            ->call('saveModifier')
            ->assertHasNoErrors();

        $pivotRow = DB::table('modifier_ingredients')
            ->where('ingredient_id', $ingredientA->id)
            ->first();

        $this->assertNotNull($pivotRow);
        $this->assertEquals($this->tenantA->id, $pivotRow->tenant_id);
        $this->assertEquals(150, $pivotRow->quantity_required);
    }
}
