<?php

declare(strict_types=1);

namespace Tests\Feature\Retail;

use App\Models\Product;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Retail\Models\ShelfLabel;
use App\Modules\Retail\Services\BarcodeSvgGenerator;
use App\Modules\Settings\Models\Store;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ShelfLabelPrintTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;
    private Store $store;
    private User $managerUser;
    private Product $product;

    protected function afterSubclassSetUp(): void
    {
        $this->tenant = $this->defaultTenant;
        $this->store = $this->defaultStore;
        $this->managerUser = User::where('tenant_id', $this->tenant->id)->firstOrFail();

        $role = Role::firstOrCreate(
            ['name' => 'Manager'],
            ['description' => 'Store Manager', 'is_admin' => false]
        );

        $this->managerUser->update([
            'role_id' => $role->id,
            'email_verified_at' => now(),
            'onboarding_completed' => true,
        ]);

        $category = \App\Modules\Products\Models\Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Fashion',
        ]);

        $this->product = Product::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_name' => 'Kemeja Batik Katun Premium',
            'product_code' => 'BATIK-001',
            'sku' => 'BATIK-SL-M',
            'barcode' => '8991234567890',
            'product_price' => 249000,
            'product_cost' => 120000,
            'product_type' => 'standard',
            'is_active' => true,
        ]);
    }

    public function test_barcode_svg_generator_produces_scalable_code128_svg(): void
    {
        $generator = app(BarcodeSvgGenerator::class);
        $svg = $generator->renderSvg('8991234567890', 40, 1.2);

        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('xmlns="http://www.w3.org/2000/svg"', $svg);
        $this->assertStringContainsString('<rect', $svg);
        $this->assertStringContainsString('</svg>', $svg);
    }

    public function test_print_batch_endpoint_renders_labels_and_marks_batch_as_printed(): void
    {
        $batchNumber = 'BAT-TEST-'.uniqid();

        // Create 2 pending shelf labels in this batch
        $label1 = ShelfLabel::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'product_id' => $this->product->id,
            'old_price' => 249000,
            'new_price' => 249000,
            'batch_number' => $batchNumber,
            'status' => ShelfLabel::STATUS_PENDING,
        ]);

        $label2 = ShelfLabel::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'product_id' => $this->product->id,
            'old_price' => 249000,
            'new_price' => 249000,
            'batch_number' => $batchNumber,
            'status' => ShelfLabel::STATUS_PENDING,
        ]);

        $response = $this->actingAs($this->managerUser)
            ->get(route('retail.shelf-labels.print-batch', $batchNumber));

        $response->assertStatus(200);
        $response->assertSee('Kemeja Batik Katun Premium');
        $response->assertSee('249.000');
        $response->assertSee('8991234567890');
        $response->assertSee('60mm 30mm');
        $response->assertSee($batchNumber);

        // Verify status was updated to PRINTED
        $this->assertSame(ShelfLabel::STATUS_PRINTED, $label1->fresh()->status);
        $this->assertSame(ShelfLabel::STATUS_PRINTED, $label2->fresh()->status);
        $this->assertNotNull($label1->fresh()->printed_at);
        $this->assertEquals($this->managerUser->id, $label1->fresh()->printed_by_user_id);
    }

    public function test_print_batch_non_existent_redirects_with_error(): void
    {
        $response = $this->actingAs($this->managerUser)
            ->get(route('retail.shelf-labels.print-batch', 'NON-EXISTENT-BATCH'));

        $response->assertRedirect(route('retail.shelf-labels.index'));
        $response->assertSessionHas('error');
    }
}
