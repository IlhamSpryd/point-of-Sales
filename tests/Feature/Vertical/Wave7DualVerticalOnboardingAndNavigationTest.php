<?php

declare(strict_types=1);

namespace Tests\Feature\Vertical;

use App\Core\Context\TenantContext;
use App\Modules\Organization\Models\Role;
use App\Modules\Organization\Models\Setting;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Models\Tenant;
use App\Modules\Organization\Models\User;
use App\Modules\Organization\Services\RoleService;
use App\Platform\Navigation\NavigationRegistry;
use App\Platform\Navigation\NavigationVisibilityResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Wave 7 Checkpoint 7A Verification:
 * Dual-Vertical Architecture (F&B / Retail / Hybrid), Owner Self-Serve Onboarding & Vertical-Adaptive Navigation.
 */
class Wave7DualVerticalOnboardingAndNavigationTest extends TestCase
{
    use DatabaseTransactions;

    protected bool $optOutFromDefaultTenant = true;

    protected function afterSubclassSetUp(): void
    {
        config(['pos.allow_web_registration' => true]);
    }

    /**
     * Case 1: Owner registers with F&B vertical.
     * Verified:
     * - Tenant.primary_industry = 'fnb'
     * - Store.store_vertical = 'fnb', default_channel = 'dine_in'
     * - Presets: PB1 10% (0.10) + Service Charge 5% (0.05)
     * - Navigation: KDS & Tables are VISIBLE; Retail Shelf Labels & Exchanges are HIDDEN
     */
    public function test_owner_registration_fnb_vertical_presets_and_navigation(): void
    {
        $response = $this->post('/register', [
            'name' => 'F&B Owner',
            'business_name' => 'Resto Nusantara Gourmet',
            'email' => 'fnb.owner.'.uniqid().'@example.test',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'primary_industry' => 'fnb',
            'business_subtype' => 'restaurant',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('verification.notice'));

        $tenant = Tenant::where('name', 'Resto Nusantara Gourmet')->first();
        $this->assertNotNull($tenant);
        $this->assertSame('fnb', $tenant->primary_industry);
        $this->assertSame('restaurant', $tenant->business_subtype);
        $this->assertTrue($tenant->supportsFnb());
        $this->assertFalse($tenant->supportsRetail());

        // Initial store verification
        $store = Store::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($store);
        $this->assertSame('fnb', $store->store_vertical);
        $this->assertSame('dine_in', $store->default_channel);
        $this->assertTrue($store->supportsFnb());
        $this->assertFalse($store->supportsRetail());

        // Tax & Service Charge presets
        $taxRate = Setting::withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('key', 'tax_rate')
            ->value('value');
        $scRate = Setting::withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('key', 'service_charge_rate')
            ->value('value');

        $this->assertSame('0.10', $taxRate);
        $this->assertSame('0.05', $scRate);

        // Navigation visibility verification
        $context = app(TenantContext::class);
        $context->setTenantId($tenant->id);
        $context->setStoreId($store->id);

        app(RoleService::class)->provisionDefaultRoles($tenant->id);
        $ownerUser = User::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();

        $registry = app(NavigationRegistry::class);
        $registry->ensureLoaded();
        $resolver = app(NavigationVisibilityResolver::class);

        // F&B destinations must be visible
        $tablesNode = $registry->find('nav.frontline.desk.tables');
        $this->assertNotNull($tablesNode);
        $this->assertTrue($resolver->isVisible($tablesNode, $ownerUser), 'Tables must be visible for F&B');

        $kdsNode = $registry->find('nav.frontline.desk.kds');
        $this->assertNotNull($kdsNode);
        $this->assertTrue($resolver->isVisible($kdsNode, $ownerUser), 'KDS must be visible for F&B');

        $modifiersNode = $registry->find('nav.catalog.master.modifiers');
        $this->assertNotNull($modifiersNode);
        $this->assertTrue($resolver->isVisible($modifiersNode, $ownerUser), 'Modifiers must be visible for F&B');

        // Retail-exclusive destinations must be HIDDEN
        $shelfLabelsNode = $registry->find('nav.catalog.merch.shelf_labels');
        $this->assertNotNull($shelfLabelsNode);
        $this->assertFalse($resolver->isVisible($shelfLabelsNode, $ownerUser), 'Shelf labels must be hidden for F&B');

        $exchangesNode = $registry->find('nav.catalog.merch.exchanges');
        $this->assertNotNull($exchangesNode);
        $this->assertFalse($resolver->isVisible($exchangesNode, $ownerUser), 'Item exchanges must be hidden for F&B');
    }

    /**
     * Case 2: Owner registers with Retail vertical.
     * Verified:
     * - Tenant.primary_industry = 'retail'
     * - Store.store_vertical = 'retail', default_channel = 'counter_retail'
     * - Presets: PPN 11% (0.11) + Service Charge 0% (0)
     * - Navigation: KDS & Tables are HIDDEN; Retail Shelf Labels & Exchanges are VISIBLE
     * - Server-side vertical guard: GET /kds is rejected with 403
     */
    public function test_owner_registration_retail_vertical_presets_and_fail_closed_guard(): void
    {
        $response = $this->post('/register', [
            'name' => 'Retail Owner',
            'business_name' => 'Minimarket Barokah',
            'email' => 'retail.owner.'.uniqid().'@example.test',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'primary_industry' => 'retail',
            'business_subtype' => 'minimarket',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('verification.notice'));

        $tenant = Tenant::where('name', 'Minimarket Barokah')->first();
        $this->assertNotNull($tenant);
        $this->assertSame('retail', $tenant->primary_industry);
        $this->assertSame('minimarket', $tenant->business_subtype);
        $this->assertFalse($tenant->supportsFnb());
        $this->assertTrue($tenant->supportsRetail());

        // Initial store verification
        $store = Store::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($store);
        $this->assertSame('retail', $store->store_vertical);
        $this->assertSame('counter_retail', $store->default_channel);
        $this->assertFalse($store->supportsFnb());
        $this->assertTrue($store->supportsRetail());

        // Tax presets: PPN 11%, SC 0%
        $taxRate = Setting::withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('key', 'tax_rate')
            ->value('value');
        $scRate = Setting::withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('key', 'service_charge_rate')
            ->value('value');

        $this->assertSame('0.11', $taxRate);
        $this->assertSame('0', $scRate);

        // Navigation visibility verification
        $context = app(TenantContext::class);
        $context->setTenantId($tenant->id);
        $context->setStoreId($store->id);

        app(RoleService::class)->provisionDefaultRoles($tenant->id);
        $ownerUser = User::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();

        $registry = app(NavigationRegistry::class);
        $registry->ensureLoaded();
        $resolver = app(NavigationVisibilityResolver::class);

        // F&B destinations must be HIDDEN
        $tablesNode = $registry->find('nav.frontline.desk.tables');
        $this->assertNotNull($tablesNode);
        $this->assertFalse($resolver->isVisible($tablesNode, $ownerUser), 'Tables must be hidden for Retail');

        $kdsNode = $registry->find('nav.frontline.desk.kds');
        $this->assertNotNull($kdsNode);
        $this->assertFalse($resolver->isVisible($kdsNode, $ownerUser), 'KDS must be hidden for Retail');

        // Retail destinations must be VISIBLE
        $shelfLabelsNode = $registry->find('nav.catalog.merch.shelf_labels');
        $this->assertNotNull($shelfLabelsNode);
        $this->assertTrue($resolver->isVisible($shelfLabelsNode, $ownerUser), 'Shelf labels must be visible for Retail');

        $exchangesNode = $registry->find('nav.catalog.merch.exchanges');
        $this->assertNotNull($exchangesNode);
        $this->assertTrue($resolver->isVisible($exchangesNode, $ownerUser), 'Item exchanges must be visible for Retail');

        // Server-side vertical guard verification: Retail user accessing /kds is rejected with 403
        $ownerUser->email_verified_at = now();
        $ownerUser->save();
        $tenant->onboarding_status = Tenant::ONBOARDING_COMPLETED;
        $tenant->access_status = Tenant::ACCESS_ACTIVE;
        $tenant->save();

        $this->actingAs($ownerUser);
        $kdsResponse = $this->get('/kds');
        $kdsResponse->assertStatus(403);
    }

    /**
     * Case 3: Hybrid Tenant sees all destinations across F&B and Retail (9x19x45 full matrix).
     */
    public function test_hybrid_vertical_sees_both_fnb_and_retail_destinations(): void
    {
        $tenant = Tenant::create([
            'name' => 'Hybrid Superstore',
            'slug' => 'hybrid-superstore-'.uniqid(),
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'primary_industry' => 'hybrid',
            'business_subtype' => 'hybrid_store',
        ]);

        $store = Store::create([
            'tenant_id' => $tenant->id,
            'name' => 'Hybrid Main Outlet',
            'store_vertical' => 'hybrid',
            'default_channel' => 'dine_in',
            'is_active' => true,
        ]);

        $context = app(TenantContext::class);
        $context->setTenantId($tenant->id);
        $context->setStoreId($store->id);

        app(RoleService::class)->provisionDefaultRoles($tenant->id);
        $ownerRole = Role::where('tenant_id', $tenant->id)->where('name', 'Owner')->first();

        $user = User::create([
            'tenant_id' => $tenant->id,
            'role_id' => $ownerRole->id,
            'name' => 'Hybrid Owner',
            'email' => 'hybrid.owner.'.uniqid().'@example.test',
            'password_hash' => bcrypt('Password123!'),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->assertTrue($tenant->supportsFnb());
        $this->assertTrue($tenant->supportsRetail());
        $this->assertTrue($store->supportsFnb());
        $this->assertTrue($store->supportsRetail());

        $registry = app(NavigationRegistry::class);
        $registry->ensureLoaded();
        $resolver = app(NavigationVisibilityResolver::class);

        // Both F&B and Retail destinations must be visible
        $tablesNode = $registry->find('nav.frontline.desk.tables');
        $this->assertTrue($resolver->isVisible($tablesNode, $user));

        $kdsNode = $registry->find('nav.frontline.desk.kds');
        $this->assertTrue($resolver->isVisible($kdsNode, $user));

        $shelfLabelsNode = $registry->find('nav.catalog.merch.shelf_labels');
        $this->assertTrue($resolver->isVisible($shelfLabelsNode, $user));

        $exchangesNode = $registry->find('nav.catalog.merch.exchanges');
        $this->assertTrue($resolver->isVisible($exchangesNode, $user));
    }
}
