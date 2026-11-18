<?php

declare(strict_types=1);

namespace Tests\Feature\Subscription;

use App\Core\Context\TenantContext;
use App\Livewire\Subscription\SubscriptionPortal;
use App\Modules\Organization\Models\Role;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Models\Tenant;
use App\Modules\Organization\Models\User;
use App\Modules\Subscription\Application\Services\SubscriptionBillingOrchestrator;
use App\Modules\Subscription\Models\Plan;
use App\Modules\Subscription\Models\ProvisioningRequest;
use App\Modules\Subscription\Models\TenantSubscription;
use App\Platform\Money\Money;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Tests self-serve SaaS plan upgrade, automated billing orchestration, and tenant entitlement activation (EXT-01, ADR-113).
 */
class SelfServeSubscriptionUpgradeTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;

    private Store $store;

    private User $user;

    private Plan $starterPlan;

    private Plan $enterprisePlan;

    private SubscriptionBillingOrchestrator $orchestrator;

    protected function afterSubclassSetUp(): void
    {
        $this->tenant = Tenant::create([
            'name' => 'SaaS Upgrade Corp '.uniqid(),
            'slug' => 'saas-upg-'.uniqid(),
            'status' => 'ACTIVE',
            'access_status' => Tenant::ACCESS_ACTIVE,
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'subscription_plan' => 'starter-plan',
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'HQ Store',
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
            'name' => 'Billing Admin',
            'email' => 'billing.'.uniqid().'@saascorp.test',
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        $this->starterPlan = Plan::firstOrCreate(
            ['code' => 'starter-plan'],
            ['name' => 'Starter Plan', 'price' => 299000.00, 'billing_cycle' => 'monthly', 'is_active' => true]
        );

        $this->enterprisePlan = Plan::firstOrCreate(
            ['code' => 'enterprise-plan'],
            ['name' => 'Enterprise Unlimited', 'price' => 1499000.00, 'billing_cycle' => 'monthly', 'is_active' => true]
        );

        TenantSubscription::create([
            'tenant_id' => $this->tenant->id,
            'plan_id' => $this->starterPlan->id,
            'status' => TenantSubscription::STATUS_ACTIVE,
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->addDays(5),
        ]);

        $this->orchestrator = app(SubscriptionBillingOrchestrator::class);
    }

    public function test_initiate_upgrade_calculates_money_amount_and_persists_order_record(): void
    {
        $this->actingAs($this->user);

        $result = $this->orchestrator->initiateUpgrade($this->tenant->id, $this->enterprisePlan->id, 'monthly');

        $this->assertArrayHasKey('order_id', $result);
        $this->assertArrayHasKey('payment_token', $result);
        $this->assertInstanceOf(Money::class, $result['amount']);
        $this->assertSame(149900000, $result['amount']->minor);
        $this->assertSame($this->enterprisePlan->id, $result['plan_id']);

        $this->assertDatabaseHas('provisioning_requests', [
            'id' => $result['provisioning_request_id'],
            'result_tenant_id' => $this->tenant->id,
            'plan_id' => $this->enterprisePlan->id,
            'status' => ProvisioningRequest::STATUS_PENDING,
        ]);
    }

    public function test_activate_paid_subscription_updates_subscription_and_writes_immutable_audit(): void
    {
        $this->actingAs($this->user);

        $paymentRef = 'MIDTRANS-SETTLED-'.Str::random(10);
        $sub = $this->orchestrator->activatePaidSubscription($this->tenant->id, $this->enterprisePlan->id, $paymentRef);

        $this->assertSame($this->enterprisePlan->id, $sub->plan_id);
        $this->assertSame(TenantSubscription::STATUS_ACTIVE, $sub->status);
        $this->assertNull($sub->trial_ends_at);
        $this->assertSame($paymentRef, $sub->metadata['payment_reference']);

        // Verify Tenant's active subscription_plan updated
        $this->assertSame('enterprise-plan', $this->tenant->fresh()->subscription_plan);

        // Verify immutable audit log
        $this->assertDatabaseHas('activity_logs', [
            'tenant_id' => $this->tenant->id,
            'action' => 'subscription.plan.upgraded',
            'subject_type' => 'tenant_subscription',
            'subject_id' => $sub->id,
        ]);
    }

    public function test_livewire_subscription_portal_supports_instant_self_serve_upgrade(): void
    {
        Livewire::actingAs($this->user)
            ->test(SubscriptionPortal::class)
            ->set('selectedPlanId', $this->enterprisePlan->id)
            ->call('confirmInstantUpgrade')
            ->assertHasNoErrors()
            ->assertSet('isUpgradeModalOpen', false)
            ->assertSee('Paket berhasil diupgrade ke Enterprise Unlimited');

        $this->assertSame('enterprise-plan', $this->tenant->fresh()->subscription_plan);
    }

    public function test_cross_tenant_isolation_on_subscription_upgrade(): void
    {
        $otherTenant = Tenant::create([
            'name' => 'Other Tenant '.uniqid(),
            'slug' => 'other-tenant-'.uniqid(),
            'status' => 'ACTIVE',
            'access_status' => Tenant::ACCESS_ACTIVE,
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'subscription_plan' => 'starter-plan',
        ]);

        $otherSub = app(TenantContext::class)->runAs($otherTenant->id, null, function () use ($otherTenant) {
            return TenantSubscription::create([
                'tenant_id' => $otherTenant->id,
                'plan_id' => $this->starterPlan->id,
                'status' => TenantSubscription::STATUS_ACTIVE,
                'starts_at' => now()->subMonth(),
                'ends_at' => now()->addDays(10),
            ]);
        });

        $this->actingAs($this->user);

        // Upgrading $this->tenant must not affect $otherTenant
        $this->orchestrator->activatePaidSubscription($this->tenant->id, $this->enterprisePlan->id, 'PAY-ISOLATION-01');

        $this->assertSame('starter-plan', $otherTenant->fresh()->subscription_plan);
        $this->assertSame($this->starterPlan->id, $otherSub->fresh()->plan_id);
    }
}
