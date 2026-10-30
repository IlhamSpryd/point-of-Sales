<?php

namespace Tests\Feature\Reports;

use App\Models\Role;
use App\Models\User;
use App\Modules\Reports\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardMetricsAndButtonsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $ownerRole = Role::firstOrCreate(
            ['name' => 'Owner', 'tenant_id' => $this->defaultTenant->id],
            ['display_name' => 'Owner', 'is_admin' => true]
        );

        $this->user = User::factory()->create([
            'tenant_id' => $this->defaultTenant->id,
            'role_id' => $ownerRole->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        if ($this->defaultStore) {
            $this->user->stores()->syncWithoutDetaching([$this->defaultStore->id => ['tenant_id' => $this->defaultTenant->id]]);
        }
    }

    public function test_dashboard_service_computes_all_histogram_periods(): void
    {
        $service = app(DashboardService::class);
        $metrics = $service->getDashboardMetrics();

        $this->assertArrayHasKey('monthlyHistogram', $metrics);
        $this->assertArrayHasKey('weeklyHistogram', $metrics);
        $this->assertArrayHasKey('yearlyHistogram', $metrics);

        $this->assertCount(31, $metrics['monthlyHistogram']);
        $this->assertCount(7, $metrics['weeklyHistogram']);
        $this->assertCount(12, $metrics['yearlyHistogram']);

        // Assert schema structure on weekly
        $firstWeekly = $metrics['weeklyHistogram'][0];
        $this->assertArrayHasKey('day', $firstWeekly);
        $this->assertArrayHasKey('label', $firstWeekly);
        $this->assertArrayHasKey('date', $firstWeekly);
        $this->assertArrayHasKey('height', $firstWeekly);
        $this->assertArrayHasKey('total', $firstWeekly);
        $this->assertArrayHasKey('formatted', $firstWeekly);
        $this->assertArrayHasKey('is_active', $firstWeekly);

        // Assert schema structure on yearly
        $firstYearly = $metrics['yearlyHistogram'][0];
        $this->assertArrayHasKey('day', $firstYearly);
        $this->assertArrayHasKey('label', $firstYearly);
        $this->assertArrayHasKey('date', $firstYearly);
        $this->assertArrayHasKey('height', $firstYearly);
        $this->assertArrayHasKey('total', $firstYearly);
        $this->assertArrayHasKey('formatted', $firstYearly);
        $this->assertArrayHasKey('is_active', $firstYearly);
    }

    public function test_dashboard_view_renders_interactive_chart_and_period_selectors(): void
    {
        $this->actingAs($this->user);

        $response = $this->get(route('dashboard'));
        $response->assertStatus(200);

        // Verify Alpine.js reactive bindings for chartType and period switching
        $response->assertSee("chartType: 'bar'", false);
        $response->assertSee("chartType = 'bar'", false);
        $response->assertSee("chartType = 'spline'", false);
        $response->assertSee("period: 'monthly'", false);
        $response->assertSee("period = 'weekly'", false);
        $response->assertSee("period = 'monthly'", false);
        $response->assertSee("period = 'yearly'", false);

        // Verify SVG spline element and bar chart container
        $response->assertSee('dashboardSplineGrad', false);
        $response->assertSee('splinePaths.line', false);
        $response->assertSee('splinePaths.area', false);
    }

    public function test_guest_layout_contains_active_legal_modals(): void
    {
        $response = $this->get(route('login'));
        $response->assertStatus(200);

        // Verify active modal triggers
        $response->assertSee("modal = 'privacy'", false);
        $response->assertSee("modal = 'terms'", false);
        $response->assertSee('Kebijakan Privasi Apeiron POS (Multi-Tenant SaaS)', false);
        $response->assertSee('Syarat dan Ketentuan Layanan (Terms of Service)', false);
        $response->assertDontSee('onclick="event.preventDefault()"', false);
    }

    public function test_dashboard_metrics_return_zero_when_no_sales_exist(): void
    {
        $service = app(DashboardService::class);
        $metrics = $service->getDashboardMetrics();

        // All histograms must have 0 total and 0 count when no sales exist
        foreach ($metrics['monthlyHistogram'] as $item) {
            $this->assertEquals(0.0, (float) $item['total']);
            $this->assertEquals(0, $item['count']);
        }

        foreach ($metrics['weeklyHistogram'] as $item) {
            $this->assertEquals(0.0, (float) $item['total']);
            $this->assertEquals(0, $item['count']);
        }

        foreach ($metrics['yearlyHistogram'] as $item) {
            $this->assertEquals(0.0, (float) $item['total']);
            $this->assertEquals(0, $item['count']);
        }

        $this->assertEquals(0, $metrics['displayTodayOrders']);
        $this->assertEquals(0, $metrics['displayWeekOrders']);
        $this->assertEquals(0, $metrics['displayMonthOrders']);
        $this->assertEquals('Rp 0', $metrics['activeDayFormatted']);
    }
}
