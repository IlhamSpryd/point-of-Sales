<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TailAdminPhaseOneAdoptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_stat_card_renders_tailadmin_kpi_with_upward_trend(): void
    {
        $html = Blade::render(
            '<x-erp.stat-card
                title="Total Pendapatan"
                value="Rp 128.450.000"
                icon="payments"
                icon-variant="primary"
                change="+12.5%"
                trend-label="dibandingkan bulan lalu"
            />'
        );

        $this->assertStringContainsString('Total Pendapatan', $html);
        $this->assertStringContainsString('Rp 128.450.000', $html);
        $this->assertStringContainsString('+12.5%', $html);
        $this->assertStringContainsString('trending_up', $html);
        $this->assertStringContainsString('dibandingkan bulan lalu', $html);
        $this->assertStringContainsString('bg-muted', $html);
        $this->assertStringContainsString('numeric', $html);
    }

    public function test_stat_card_renders_tailadmin_kpi_with_downward_trend_and_warning_variant(): void
    {
        $html = Blade::render(
            '<x-erp.stat-card
                label="Retur Penjualan"
                value="Rp 2.150.000"
                icon="assignment_return"
                icon-variant="warning"
                change="-4.8%"
                change-direction="down"
                delta-variant="danger"
                subtext="vs minggu lalu"
            />'
        );

        $this->assertStringContainsString('Retur Penjualan', $html);
        $this->assertStringContainsString('Rp 2.150.000', $html);
        $this->assertStringContainsString('-4.8%', $html);
        $this->assertStringContainsString('trending_down', $html);
        $this->assertStringContainsString('vs minggu lalu', $html);
        $this->assertStringContainsString('bg-muted', $html);   // icon holder: monochrome per P2
        $this->assertStringContainsString('bg-destructive-muted', $html);
    }

    public function test_metric_card_alias_renders_tailadmin_kpi(): void
    {
        $html = Blade::render(
            '<x-erp.metric-card
                title="Active Users"
                value="2,420"
                change="+18.2%"
                direction="up"
                icon="group"
                icon-variant="primary"
                trend-label="vs last month"
            />'
        );

        $this->assertStringContainsString('Active Users', $html);
        $this->assertStringContainsString('2,420', $html);
        $this->assertStringContainsString('+18.2%', $html);
        $this->assertStringContainsString('trending_up', $html);
    }

    public function test_common_component_card_renders_header_and_body(): void
    {
        $html = Blade::render(
            '<x-common.component-card title="Sales Overview" desc="Monthly breakdown of revenue">
                <p>Card Body Content</p>
            </x-common.component-card>'
        );

        $this->assertStringContainsString('Sales Overview', $html);
        $this->assertStringContainsString('Monthly breakdown of revenue', $html);
        $this->assertStringContainsString('Card Body Content', $html);
    }

    public function test_common_page_breadcrumb_renders_tailadmin_breadcrumb(): void
    {
        $html = Blade::render(
            '<x-common.page-breadcrumb pageTitle="Sales Order Management" />'
        );

        $this->assertStringContainsString('Sales Order Management', $html);
        $this->assertStringContainsString('Home', $html);
        $this->assertStringContainsString('chevron_right', $html);
    }

    public function test_header_search_renders_desktop_and_mobile_triggers_with_command_open_event(): void
    {
        $html = Blade::render('<x-ui.header-search />');

        $this->assertStringContainsString('command-open', $html);
        $this->assertStringContainsString('search', $html);
        $this->assertStringContainsString('⌘', $html);
        $this->assertStringContainsString('Search navigation &amp; features…', $html);
    }

    public function test_notification_dropdown_renders_popover_tabs_and_bell(): void
    {
        $html = Blade::render('<x-erp.notification-dropdown />');

        $this->assertStringContainsString('notifications', $html);
        $this->assertStringContainsString('Notifications', $html);
        $this->assertStringContainsString('Unread', $html);
        $this->assertStringContainsString('Mark all as read', $html);
    }

    public function test_header_user_dropdown_renders_user_menu_and_actions(): void
    {
        $tenant = Tenant::create(['name' => 'User Dropdown Tenant', 'slug' => 'user-dropdown-tenant', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'Owner', 'permissions' => ['*']]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Budi Prasetyo', 'email' => 'budi@example.com', 'role_id' => $role->id]);

        $this->actingAs($user);
        $html = Blade::render('<x-header.user-dropdown />');

        $this->assertStringContainsString('Budi Prasetyo', $html);
        $this->assertStringContainsString('budi@example.com', $html);
        $this->assertStringContainsString('Owner', $html);
        $this->assertStringContainsString('My Profile', $html);
        $this->assertStringContainsString('Sign Out', $html);
    }

    public function test_app_shell_header_integrates_search_and_notifications(): void
    {
        $tenant = Tenant::create([
            'name' => 'TailAdmin Shell Tenant',
            'slug' => 'tailadmin-shell-tenant',
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);
        app(TenantContext::class)->setTenantId($tenant->id);

        $store = Store::create([
            'tenant_id' => $tenant->id,
            'name' => 'TailAdmin Store',
            'is_active' => true,
        ]);
        app(TenantContext::class)->setStoreId($store->id);

        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'Owner', 'permissions' => ['*']]);
        $owner = User::factory()->create(['tenant_id' => $tenant->id, 'role_id' => $role->id]);

        DB::table('user_stores')->insert([
            'tenant_id' => $tenant->id,
            'user_id' => $owner->id,
            'store_id' => $store->id,
        ]);

        $response = $this->actingAs($owner)->get(route('dashboard'));
        $response->assertOk();

        // Verify TailAdmin Header Search trigger is present
        $response->assertSee('command-open', false);
        $response->assertSee('Search navigation &amp; features…', false);

        // Verify TailAdmin Notification Dropdown is present
        $response->assertSee('Notifications', false);
        $response->assertSee('notifications', false);
    }
}
