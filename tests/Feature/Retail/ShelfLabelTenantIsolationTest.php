<?php

declare(strict_types=1);

namespace Tests\Feature\Retail;

use App\Modules\Retail\Models\ShelfLabel;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

class ShelfLabelTenantIsolationTest extends TestCase
{
    use DatabaseTransactions, TwoTenantFixture;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();
        $this->setupTwoTenants();
    }

    public function test_shelf_label_created_in_tenant_a_is_isolated_from_tenant_b(): void
    {
        $context = app(TenantContext::class);

        // 1. Create ShelfLabel in Tenant A
        $context->setTenantId($this->tenantA->id);

        $labelA = ShelfLabel::create([
            'store_id' => $this->storeA->id,
            'product_id' => $this->productA->id,
            'old_price' => '45000.00',
            'new_price' => '50000.00',
            'status' => ShelfLabel::STATUS_PENDING,
            'batch_number' => 'BATCH-A-01',
        ]);

        $this->assertDatabaseHas('shelf_labels', [
            'id' => $labelA->id,
            'tenant_id' => $this->tenantA->id,
        ]);

        // 2. Query in Tenant A: visible
        $this->assertNotNull(ShelfLabel::find($labelA->id));
        $this->assertCount(1, ShelfLabel::all());

        // 3. Switch context to Tenant B: invisible
        $context->setTenantId($this->tenantB->id);

        $this->assertNull(ShelfLabel::find($labelA->id));
        $this->assertCount(0, ShelfLabel::all());
        $this->assertEquals(0, ShelfLabel::where('batch_number', 'BATCH-A-01')->count());

        // 4. Create ShelfLabel in Tenant B
        $labelB = ShelfLabel::create([
            'store_id' => $this->storeB->id,
            'product_id' => $this->productB->id,
            'old_price' => '70000.00',
            'new_price' => '75000.00',
            'status' => ShelfLabel::STATUS_PENDING,
            'batch_number' => 'BATCH-B-01',
        ]);

        // Tenant B only sees label B
        $this->assertCount(1, ShelfLabel::all());
        $this->assertNotNull(ShelfLabel::find($labelB->id));
        $this->assertNull(ShelfLabel::find($labelA->id));

        // Switch back to Tenant A: only sees label A
        $context->setTenantId($this->tenantA->id);
        $this->assertCount(1, ShelfLabel::all());
        $this->assertNotNull(ShelfLabel::find($labelA->id));
        $this->assertNull(ShelfLabel::find($labelB->id));
    }

    public function test_shelf_label_query_fails_closed_when_no_tenant_context(): void
    {
        $context = app(TenantContext::class);

        // Create label in Tenant A
        $context->setTenantId($this->tenantA->id);

        $labelA = ShelfLabel::create([
            'store_id' => $this->storeA->id,
            'product_id' => $this->productA->id,
            'old_price' => '20000.00',
            'new_price' => '25000.00',
            'status' => ShelfLabel::STATUS_PENDING,
        ]);

        // Clear tenant context (simulate unauthenticated or missing tenant context)
        $context->clear();

        // Fail-closed scoping ensures 0 records returned
        $this->assertCount(0, ShelfLabel::all());
        $this->assertNull(ShelfLabel::find($labelA->id));
        $this->assertEquals(0, ShelfLabel::count());

        // withoutTenantScope allows administrative bypass
        $allLabels = ShelfLabel::withoutTenantScope()->get();
        $this->assertTrue($allLabels->contains('id', $labelA->id));
    }

    public function test_shelf_label_auto_assigns_tenant_id_from_context(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);

        // Create without passing tenant_id explicitly
        $label = ShelfLabel::create([
            'store_id' => $this->storeA->id,
            'product_id' => $this->productA->id,
            'old_price' => '12000.00',
            'new_price' => '15000.00',
            'status' => ShelfLabel::STATUS_PENDING,
        ]);

        $this->assertEquals($this->tenantA->id, $label->tenant_id);
    }
}
