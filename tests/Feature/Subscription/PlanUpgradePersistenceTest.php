<?php

declare(strict_types=1);

namespace Tests\Feature\Subscription;

use App\Livewire\Subscription\SubscriptionPortal;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Settings\Models\ProvisioningRequest;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class PlanUpgradePersistenceTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;
    private Store $store;
    private User $user;
    private Plan $starterPlan;
    private Plan $enterprisePlan;

    protected function afterSubclassSetUp(): void
    {
        $this->tenant = Tenant::create([
            'name' => 'Upgrade Test Tenant '.uniqid(),
            'code' => 'UPG-'.strtoupper(Str::random(4)),
            'slug' => 'upg-'.uniqid(),
            'status' => 'ACTIVE',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Flagship Store',
            'is_active' => true,
        ]);

        app(TenantContext::class)->setStoreId($this->store->id);

        $role = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Owner',
            'permissions' => ['*'],
        ]);

        $this->user = User::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Tenant Owner',
            'email' => 'owner.upg.'.uniqid().'@example.com',
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        $this->starterPlan = Plan::firstOrCreate(
            ['code' => 'starter-tier-test'],
            ['name' => 'Starter Tier', 'price' => 199000.00, 'billing_cycle' => 'monthly', 'is_active' => true]
        );

        $this->enterprisePlan = Plan::firstOrCreate(
            ['code' => 'enterprise-tier-test'],
            ['name' => 'Enterprise Pro', 'price' => 999000.00, 'billing_cycle' => 'monthly', 'is_active' => true]
        );
    }

    public function test_request_plan_upgrade_persists_provisioning_request_record(): void
    {
        $this->assertDatabaseMissing('provisioning_requests', [
            'result_tenant_id' => $this->tenant->id,
            'plan_id' => $this->enterprisePlan->id,
        ]);

        Livewire::actingAs($this->user)
            ->test(SubscriptionPortal::class)
            ->set('selectedPlanId', $this->enterprisePlan->id)
            ->call('requestPlanUpgrade')
            ->assertHasNoErrors()
            ->assertSet('isUpgradeModalOpen', false)
            ->assertSee('Permintaan upgrade ke paket Enterprise Pro telah dicatat');

        $this->assertDatabaseHas('provisioning_requests', [
            'result_tenant_id' => $this->tenant->id,
            'result_user_id' => $this->user->id,
            'plan_id' => $this->enterprisePlan->id,
            'status' => 'pending',
        ]);

        $record = ProvisioningRequest::where('result_tenant_id', $this->tenant->id)
            ->where('plan_id', $this->enterprisePlan->id)
            ->first();

        $this->assertNotNull($record);
        $this->assertStringContainsString('Permintaan upgrade paket', (string) $record->notes);
        $this->assertSame($this->user->email, $record->owner_email);
    }

    public function test_subscription_portal_displays_pending_upgrade_banner(): void
    {
        // First create a pending request
        ProvisioningRequest::create([
            'idempotency_key' => 'upgrade-test-banner-'.uniqid(),
            'request_fingerprint' => hash('sha256', 'banner-test-'.$this->tenant->id),
            'owner_email' => $this->user->email,
            'status' => 'pending',
            'result_tenant_id' => $this->tenant->id,
            'result_user_id' => $this->user->id,
            'plan_id' => $this->enterprisePlan->id,
            'notes' => 'Upgrade to Enterprise Pro',
        ]);

        Livewire::actingAs($this->user)
            ->test(SubscriptionPortal::class)
            ->assertSee('Permintaan Upgrade Paket Sedang Diproses')
            ->assertSee('Enterprise Pro')
            ->assertSee('pending');
    }
}
