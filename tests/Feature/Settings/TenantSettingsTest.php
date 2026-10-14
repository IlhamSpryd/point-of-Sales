<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Services\Context\TenantContext;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

class TenantSettingsTest extends TestCase
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

    public function test_owner_can_access_settings_page(): void
    {
        $response = $this->actingAs($this->userAOwner)
            ->withSession(['current_store_id' => $this->storeA->id])
            ->get(route('settings.index'));

        $response->assertStatus(200);
        $response->assertViewHas('settings');
    }

    public function test_barista_cannot_access_settings_page(): void
    {
        $response = $this->actingAs($this->userBBarista)
            ->withSession(['current_store_id' => $this->storeB->id])
            ->get(route('settings.index'));

        $response->assertStatus(403);
    }

    public function test_owner_can_update_settings_and_transaction_service_uses_them(): void
    {
        $response = $this->actingAs($this->userAOwner)
            ->withSession(['current_store_id' => $this->storeA->id])
            ->post(route('settings.update'), [
                'tax_rate' => 0.10,
                'rounding_value' => 500,
                'rounding_behavior' => 'ROUND_UP',
            ]);

        $response->assertRedirect(route('settings.index'));

        app(TenantContext::class)->setTenantId($this->tenantA->id);

        $this->assertEquals(0.10, Setting::get('tax_rate'));
        $this->assertEquals(500, Setting::get('rounding_value'));
        $this->assertEquals('ROUND_UP', Setting::get('rounding_behavior'));

        // Verify TransactionService calculation with custom settings
        $service = app(TransactionService::class);
        $totals = $service->calculateOrderTotals(10123);
        $this->assertEquals(1012, $totals['tax_amount']);
        $this->assertEquals(11500, $totals['total_amount']);
    }
}
