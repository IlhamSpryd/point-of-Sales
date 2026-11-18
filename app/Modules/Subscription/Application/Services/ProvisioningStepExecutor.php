<?php

declare(strict_types=1);

namespace App\Modules\Subscription\Application\Services;

use App\Modules\Subscription\Models\Plan;
use App\Modules\Subscription\Models\ProvisioningStep;
use App\Modules\Subscription\Models\TenantSubscription;
use Illuminate\Support\Str;

/**
 * Executes and persists individual granular onboarding steps within the atomic provisioning transaction (EXT-03, R-19).
 */
class ProvisioningStepExecutor
{
    public function __construct(
        private readonly \App\Modules\Organization\Services\TenantProvisioningService $tenantProvisioning,
        private readonly ProvisioningStepTracker $stepTracker,
    ) {}

    /**
     * Step 1: Create or fetch Tenant aggregate.
     *
     * @param  array<string, mixed>  $data
     */
    public function executeCreateTenant(int $requestId, array $data, string $idempotencyKey): \App\Modules\Organization\Models\Tenant
    {
        return $this->stepTracker->runStep($requestId, ProvisioningStep::STEP_CREATE_TENANT, null, function () use ($data, $idempotencyKey) {
            return \App\Modules\Organization\Models\Tenant::firstOrCreate(
                ['provisioning_idempotency_key' => $idempotencyKey],
                [
                    'name' => $data['tenant_name'],
                    'readiness_status' => \App\Modules\Organization\Services\TenantProvisioningService::READINESS_PENDING,
                ]
            );
        });
    }

    /**
     * Step 2: Create default company and physical branch/store.
     *
     * @param  array<string, mixed>  $data
     * @return array{company: \App\Modules\Organization\Models\Company|null, store: \App\Modules\Organization\Models\Store}
     */
    public function executeCreateCompanyAndBranch(int $requestId, \App\Modules\Organization\Models\Tenant $tenant, array $data): array
    {
        return $this->stepTracker->runStep($requestId, ProvisioningStep::STEP_CREATE_COMPANY_AND_BRANCH, $tenant->id, function () use ($tenant, $data) {
            $company = \App\Modules\Organization\Models\Company::where('tenant_id', $tenant->id)
                ->where('is_default', true)
                ->first();

            if (! $company) {
                $company = \App\Modules\Organization\Models\Company::create([
                    'tenant_id' => $tenant->id,
                    'company_code' => 'CMP-'.str_pad((string) $tenant->id, 4, '0', STR_PAD_LEFT),
                    'company_name' => $data['tenant_name'],
                    'legal_name' => $data['tenant_name'],
                    'is_default' => true,
                    'is_active' => true,
                ]);
            }

            $store = \App\Modules\Organization\Models\Store::where('tenant_id', $tenant->id)
                ->first();

            if (! $store) {
                $store = \App\Modules\Organization\Models\Store::create([
                    'tenant_id' => $tenant->id,
                    'name' => $data['tenant_name'],
                ]);
            }

            return ['company' => $company, 'store' => $store];
        });
    }

    /**
     * Step 3: Seed owner role, owner user, and onboarding token.
     *
     * @param  array<string, mixed>  $data
     * @return array{owner: \App\Modules\Organization\Models\User, onboarding_token: string}
     */
    public function executeSeedRolesAndOwner(int $requestId, \App\Modules\Organization\Models\Tenant $tenant, array $data): array
    {
        return $this->stepTracker->runStep($requestId, ProvisioningStep::STEP_SEED_ROLES_AND_OWNER, $tenant->id, function () use ($tenant, $data) {
            $ownerRole = \App\Modules\Organization\Models\Role::firstOrCreate(
                ['name' => 'Owner', 'tenant_id' => $tenant->id],
                [
                    'role_code' => 'ROL-OWNER',
                    'description' => 'Pemilik bisnis — akses penuh',
                    'permissions' => ['*'],
                    'is_active' => true,
                ]
            );

            $owner = \App\Modules\Organization\Models\User::where('tenant_id', $tenant->id)
                ->where('email', $data['owner_email'])
                ->first();

            if (! $owner) {
                $owner = \App\Modules\Organization\Models\User::create([
                    'tenant_id' => $tenant->id,
                    'name' => $data['owner_name'],
                    'email' => $data['owner_email'],
                    'phone_number' => $data['owner_phone'] ?? null,
                    'password' => null,
                    'role_id' => $ownerRole->id,
                    'is_active' => true,
                    'email_verified_at' => null,
                    'join_date' => now()->toDateString(),
                ]);
            }

            $plaintextToken = Str::random(48);
            $tokenHash = hash('sha256', $plaintextToken);

            \App\Modules\Organization\Models\OwnerOnboardingToken::create([
                'tenant_id' => $tenant->id,
                'user_id' => $owner->id,
                'token_hash' => $tokenHash,
                'expires_at' => now()->addHours((int) config('pos.provisioning.onboarding_token_ttl_hours', 48)),
                'send_attempts' => 1,
            ]);

            return [
                'owner' => $owner,
                'onboarding_token' => $plaintextToken,
            ];
        });
    }

    /**
     * Step 4: Seed chart of accounts, system users, and baseline settings.
     */
    public function executeSeedChartOfAccounts(int $requestId, \App\Modules\Organization\Models\Tenant $tenant, \App\Modules\Organization\Models\User $owner): void
    {
        $this->stepTracker->runStep($requestId, ProvisioningStep::STEP_SEED_CHART_OF_ACCOUNTS, $tenant->id, function () use ($tenant, $owner) {
            $this->tenantProvisioning->provisionBaseline($tenant, $owner);

            return true;
        });
    }

    /**
     * Step 5: Activate default or specified subscription plan.
     */
    public function executeActivateSubscriptionPlan(int $requestId, \App\Modules\Organization\Models\Tenant $tenant, ?int $planId = null): void
    {
        $this->stepTracker->runStep($requestId, ProvisioningStep::STEP_ACTIVATE_SUBSCRIPTION_PLAN, $tenant->id, function () use ($tenant, $planId) {
            $subscription = TenantSubscription::where('tenant_id', $tenant->id)
                ->first();

            if (! $subscription) {
                $plan = $planId ? Plan::find($planId) : null;
                if (! $plan) {
                    $planCode = $tenant->subscription_plan ?? 'standard_trial';
                    $plan = Plan::where('code', $planCode)->first();
                }

                if ($plan) {
                    TenantSubscription::create([
                        'tenant_id' => $tenant->id,
                        'plan_id' => $plan->id,
                        'status' => TenantSubscription::STATUS_TRIALING,
                        'starts_at' => now(),
                        'trial_ends_at' => now()->addDays(14),
                    ]);
                }
            }

            return true;
        });
    }

    /**
     * Step 6: Dispatch welcome onboarding email notification via EmailOutbox.
     */
    public function executeDispatchWelcomeNotification(
        int $requestId,
        \App\Modules\Organization\Models\Tenant $tenant,
        \App\Modules\Organization\Models\User $owner,
        string $onboardingToken,
        string $emailBody
    ): int {
        return $this->stepTracker->runStep($requestId, ProvisioningStep::STEP_DISPATCH_WELCOME_NOTIFICATION, $tenant->id, function () use ($tenant, $owner, $emailBody) {
            $outbox = \App\Modules\Organization\Models\EmailOutbox::create([
                'event_type' => 'owner_onboarding',
                'recipient_email' => $owner->email,
                'subject' => 'Aktivasi akun Apeiron System — '.$tenant->name,
                'body_text' => $emailBody,
                'tenant_id' => $tenant->id,
                'user_id' => $owner->id,
                'status' => \App\Modules\Organization\Models\EmailOutbox::STATUS_PENDING,
                'attempts' => 0,
                'next_attempt_at' => now(),
            ]);

            return $outbox->id;
        });
    }

    /**
     * Record immutable audit log entry.
     */
    public function recordAudit(\App\Modules\Organization\Models\Tenant $tenant, \App\Modules\Organization\Models\Store $store, \App\Modules\Organization\Models\User $owner): void
    {
        \App\Modules\Organization\Models\ActivityLog::create([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'user_id' => $owner->id,
            'action' => 'provisioning.completed',
            'subject_type' => 'tenant',
            'subject_id' => $tenant->id,
            'description' => 'Tenant provisioned via marketing website',
            'metadata' => [
                'store_id' => $store->id,
                'idempotent_flow' => true,
            ],
        ]);
    }
}
