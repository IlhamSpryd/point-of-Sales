<?php

declare(strict_types=1);

namespace Tests\Feature\Subscription;

use App\Livewire\Subscription\SubscriptionPortal;
use App\Models\Device;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class SubscriptionPortalTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;

    private Store $store;

    private Role $role;

    private User $user;

    private Plan $starterPlan;

    private Plan $enterprisePlan;

    protected function afterSubclassSetUp(): void
    {
        $this->tenant = Tenant::create([
            'name' => 'SaaS Subscriber Group '.uniqid(),
            'code' => 'SUB-'.strtoupper(Str::random(4)),
            'slug' => 'sub-'.uniqid(),
            'status' => 'ACTIVE',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Main Outlet Grand Indonesia',
            'code' => 'STR-'.substr(uniqid(), 0, 10),
            'is_active' => true,
        ]);

        app(TenantContext::class)->setStoreId($this->store->id);

        $this->role = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Owner',
            'permissions' => ['*'],
        ]);

        $this->user = User::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Tenant Owner Administrator',
            'email' => 'owner.saas.'.uniqid().'@example.com',
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
            'role_id' => $this->role->id,
            'is_active' => true,
        ]);

        DB::table('user_stores')->insert([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
        ]);

        // Create Plans
        $this->starterPlan = Plan::firstOrCreate(
            ['code' => 'starter'],
            [
                'name' => 'Starter Tier',
                'description' => 'Entry plan for single-store business',
                'price' => '299000.00',
                'billing_cycle' => 'monthly',
                'is_active' => true,
            ]
        );

        $this->enterprisePlan = Plan::firstOrCreate(
            ['code' => 'enterprise'],
            [
                'name' => 'Enterprise Unlimited Tier',
                'description' => 'Full enterprise capability with unlimited branches and devices',
                'price' => '1499000.00',
                'billing_cycle' => 'monthly',
                'is_active' => true,
            ]
        );

        // Assign active subscription
        TenantSubscription::create([
            'tenant_id' => $this->tenant->id,
            'plan_id' => $this->starterPlan->id,
            'status' => 'active',
            'starts_at' => now()->subDays(5),
            'ends_at' => now()->addDays(25),
        ]);
    }

    public function test_subscription_portal_renders_current_tier_and_resource_quotas(): void
    {
        Livewire::actingAs($this->user)
            ->test(SubscriptionPortal::class)
            ->assertStatus(200)
            ->assertSee('SaaS Subscription & Quota Portal', false)
            ->assertSee('Physical Stores')
            ->assertSee('POS Registers')
            ->assertSee('Staff & Users', false);
    }

    public function test_subscription_portal_allows_requesting_plan_upgrade(): void
    {
        Livewire::actingAs($this->user)
            ->test(SubscriptionPortal::class)
            ->set('selectedPlanId', $this->enterprisePlan->id)
            ->call('requestPlanUpgrade')
            ->assertHasNoErrors()
            ->assertSet('isUpgradeModalOpen', false);
    }

    public function test_subscription_portal_route_access_restricted_by_role(): void
    {
        // 1. Owner can access via route
        $response = $this->actingAs($this->user)->get(route('subscription.portal'));
        $response->assertOk();

        // 2. Kasir cannot access subscription portal
        $kasirRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Kasir',
            'permissions' => ['orders.create'],
        ]);

        $kasirUser = User::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Kasir Staff',
            'email' => 'kasir.'.uniqid().'@example.com',
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
            'role_id' => $kasirRole->id,
            'is_active' => true,
        ]);

        DB::table('user_stores')->insert([
            'tenant_id' => $this->tenant->id,
            'user_id' => $kasirUser->id,
            'store_id' => $this->store->id,
        ]);

        $responseKasir = $this->actingAs($kasirUser)->get(route('subscription.portal'));
        $this->assertContains($responseKasir->status(), [403, 302]);
    }
}
