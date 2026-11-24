<?php

declare(strict_types=1);

namespace Tests\Feature\Integration;

use App\Core\Context\TenantContext;
use App\Livewire\Integration\OfdChannelConfigManager;
use App\Livewire\Kasir\CreateOrder;
use App\Modules\Integration\Models\OfdChannelConfig;
use App\Modules\Organization\Models\Role;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Models\Tenant;
use App\Modules\Organization\Models\User;
use App\Modules\Organization\Services\RoleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Wave 7.1 Checkpoint 7.1A Verification:
 * OFD Assisted Mode Blade UI, KDS Badges & OfdChannelConfigManager.
 */
class Wave71OfdUiAndConfigTest extends TestCase
{
    use DatabaseTransactions;

    protected bool $optOutFromDefaultTenant = true;

    protected Tenant $fnbTenant;

    protected Store $fnbStore;

    protected User $fnbOwner;

    protected Tenant $retailTenant;

    protected Store $retailStore;

    protected User $retailOwner;

    protected function afterSubclassSetUp(): void
    {
        // 1. Setup F&B Tenant & Store
        $this->fnbTenant = Tenant::create([
            'name' => 'F&B Resto Bistro '.uniqid(),
            'code' => 'FNB-'.strtoupper(Str::random(6)),
            'subdomain' => 'fnb-'.strtolower(Str::random(6)),
            'primary_industry' => 'fnb',
            'business_subtype' => 'restaurant',
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
        ]);

        $this->fnbStore = Store::create([
            'tenant_id' => $this->fnbTenant->id,
            'name' => 'F&B Main Outlet',
            'code' => 'STR-FNB-'.strtoupper(Str::random(4)),
            'store_vertical' => 'fnb',
            'default_channel' => 'dine_in',
            'is_active' => true,
        ]);

        $context = app(TenantContext::class);
        $context->setTenantId($this->fnbTenant->id);
        $context->setStoreId($this->fnbStore->id);

        app(RoleService::class)->provisionDefaultRoles($this->fnbTenant->id);
        $ownerRoleFnb = Role::where('tenant_id', $this->fnbTenant->id)->where('name', 'Owner')->firstOrFail();

        $this->fnbOwner = User::create([
            'name' => 'Owner F&B',
            'email' => 'owner.fnb.'.uniqid().'@resto.test',
            'password' => bcrypt('Password123!'),
            'role_id' => $ownerRoleFnb->id,
            'tenant_id' => $this->fnbTenant->id,
            'store_id' => $this->fnbStore->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        // 2. Setup Retail Tenant & Store
        $this->retailTenant = Tenant::create([
            'name' => 'Retail Superstore '.uniqid(),
            'code' => 'RTL-'.strtoupper(Str::random(6)),
            'subdomain' => 'rtl-'.strtolower(Str::random(6)),
            'primary_industry' => 'retail',
            'business_subtype' => 'convenience_store',
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
        ]);

        $context->setTenantId($this->retailTenant->id);
        $context->setStoreId(null);

        $this->retailStore = Store::create([
            'tenant_id' => $this->retailTenant->id,
            'name' => 'Retail Outlet 1',
            'code' => 'STR-RTL-'.strtoupper(Str::random(4)),
            'store_vertical' => 'retail',
            'default_channel' => 'counter_retail',
            'is_active' => true,
        ]);

        $context->setStoreId($this->retailStore->id);

        app(RoleService::class)->provisionDefaultRoles($this->retailTenant->id);
        $ownerRoleRetail = Role::where('tenant_id', $this->retailTenant->id)->where('name', 'Owner')->firstOrFail();

        $this->retailOwner = User::create([
            'name' => 'Owner Retail',
            'email' => 'owner.rtl.'.uniqid().'@superstore.test',
            'password' => bcrypt('Password123!'),
            'role_id' => $ownerRoleRetail->id,
            'tenant_id' => $this->retailTenant->id,
            'store_id' => $this->retailStore->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
    }

    /**
     * Test 1: Owner pada cabang F&B dapat mengakses /integrations/ofd-channels dan menyimpan config.
     */
    public function test_fnb_owner_can_access_and_save_ofd_channel_config(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->fnbTenant->id);
        $context->setStoreId($this->fnbStore->id);

        $this->actingAs($this->fnbOwner);

        $response = $this->get('/integrations/ofd-channels');
        $response->assertStatus(200);
        $response->assertSee('Channel Integration (OFD Channel Config)');
        $response->assertSee('GrabFood');
        $response->assertSee('GoFood');

        // Test Livewire component save
        Livewire::test(OfdChannelConfigManager::class)
            ->set('provider', 'grabfood')
            ->set('merchantId', 'GRAB_RESTO_TEST_101')
            ->set('clientId', 'client_grab_123')
            ->set('signingSecret', 'hmac_signing_secret_test_key_12345')
            ->set('commissionRatePct', 20.00)
            ->set('priceMarkupPct', 5.00)
            ->set('autoAcceptOrder', true)
            ->set('isActive', true)
            ->call('saveConfig')
            ->assertHasNoErrors()
            ->assertSee('Konfigurasi GRABFOOD berhasil disimpan.');

        $saved = OfdChannelConfig::query()
            ->where('tenant_id', $this->fnbTenant->id)
            ->where('store_id', $this->fnbStore->id)
            ->where('provider', 'grabfood')
            ->first();

        $this->assertNotNull($saved);
        $this->assertSame('GRAB_RESTO_TEST_101', $saved->merchant_id);
        $this->assertSame('20.00', (string) $saved->commission_rate_pct);
        $this->assertSame('5.00', (string) $saved->price_markup_pct);
        $this->assertTrue($saved->auto_accept_order);
        $this->assertTrue($saved->is_active);
        $this->assertSame('hmac_signing_secret_test_key_12345', $saved->signing_secret);
    }

    /**
     * Test 2: Owner pada cabang Retail murni menerima HTTP 403 saat mengakses /integrations/ofd-channels.
     */
    public function test_retail_tenant_is_forbidden_from_ofd_channels(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->retailTenant->id);
        $context->setStoreId($this->retailStore->id);

        $this->actingAs($this->retailOwner);

        $response = $this->get('/integrations/ofd-channels');
        $response->assertStatus(403);
    }

    /**
     * Test 3: Livewire POS CreateOrder pada cabang F&B menampilkan opsi grabfood & gofood dan input PIN saat dipilih.
     */
    public function test_pos_create_order_renders_ofd_assisted_mode_for_fnb_branch(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->fnbTenant->id);
        $context->setStoreId($this->fnbStore->id);

        $this->actingAs($this->fnbOwner);

        Livewire::test(CreateOrder::class)
            ->assertSee('GrabFood')
            ->assertSee('GoFood')
            ->call('setOrderType', 'grabfood')
            ->assertSet('orderType', 'grabfood')
            ->assertSee('OFD Assisted: GRABFOOD')
            ->assertSee('Kode Order / PIN')
            ->assertSee('Estimasi Net Payout (1125)');
    }

    /**
     * Test 4: Livewire POS CreateOrder pada cabang Retail murni menyembunyikan opsi grabfood dan gofood.
     */
    public function test_pos_create_order_hides_ofd_options_for_pure_retail_branch(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->retailTenant->id);
        $context->setStoreId($this->retailStore->id);

        $this->actingAs($this->retailOwner);

        Livewire::test(CreateOrder::class)
            ->assertDontSee('GrabFood')
            ->assertDontSee('GoFood')
            ->assertSee('Counter POS');
    }

    /**
     * Test 5: Cross-tenant isolation pada ofd_channel_configs.
     */
    public function test_cross_tenant_isolation_on_ofd_channel_configs(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->fnbTenant->id);
        $context->setStoreId($this->fnbStore->id);

        // Setup config for Tenant A
        OfdChannelConfig::create([
            'tenant_id' => $this->fnbTenant->id,
            'store_id' => $this->fnbStore->id,
            'provider' => 'grabfood',
            'merchant_id' => 'MERCHANT_A',
            'signing_secret' => 'secret_tenant_a_12345',
            'commission_rate_pct' => '20.00',
            'price_markup_pct' => '0.00',
            'auto_accept_order' => false,
            'is_active' => true,
        ]);

        // In context of Tenant B (Retail, or another F&B)
        $context->setTenantId($this->retailTenant->id);
        $context->setStoreId($this->retailStore->id);

        $scopedResults = OfdChannelConfig::query()->get();
        $this->assertCount(0, $scopedResults, 'Tenant B must not see Tenant A OFD configurations');

        $directLookup = OfdChannelConfig::query()
            ->where('merchant_id', 'MERCHANT_A')
            ->first();
        $this->assertNull($directLookup, 'Scoped query must not return Tenant A record');
    }
}
