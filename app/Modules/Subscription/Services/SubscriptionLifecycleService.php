<?php

declare(strict_types=1);

namespace App\Modules\Subscription\Services;

use App\Modules\Organization\Models\Tenant;
use App\Modules\Subscription\Models\Plan;
use App\Modules\Subscription\Models\TenantSubscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SubscriptionLifecycleService
{
    public const STATUS_TRIAL = TenantSubscription::STATUS_TRIALING;

    public const STATUS_ACTIVE = TenantSubscription::STATUS_ACTIVE;

    public const STATUS_GRACE_PERIOD = 'grace_period';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_ARCHIVED = 'archived';

    /**
     * Memulai masa percobaan (Trial) langganan tenant.
     */
    public function startTrial(Tenant $tenant, Plan $plan, int $trialDays = 14): TenantSubscription
    {
        return DB::transaction(function () use ($tenant, $plan, $trialDays) {
            $now = Carbon::now();
            $expiresAt = $now->copy()->addDays($trialDays);

            /** @var TenantSubscription $sub */
            // @tenant-bypass: platform_entitlement
            $sub = TenantSubscription::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                'status' => TenantSubscription::STATUS_TRIALING,
                'starts_at' => $now,
                'ends_at' => $expiresAt,
                'trial_ends_at' => $expiresAt,
            ]);

            $tenant->update([
                'subscription_plan' => $plan->code,
                'subscription_status' => Tenant::SUBSCRIPTION_TRIALING,
                'access_status' => Tenant::ACCESS_ACTIVE,
                'status' => 'active',
            ]);

            app(EntitlementService::class)->clearCache((int) $tenant->id);

            return $sub;
        });
    }

    /**
     * Mengaktifkan paket langganan aktif setelah pembayaran berhasil.
     */
    public function activate(Tenant $tenant, Plan $plan, int $durationDays = 30): TenantSubscription
    {
        return DB::transaction(function () use ($tenant, $plan, $durationDays) {
            $now = Carbon::now();
            $expiresAt = $now->copy()->addDays($durationDays);

            /** @var TenantSubscription $sub */
            // @tenant-bypass: platform_entitlement
            $sub = TenantSubscription::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                'status' => TenantSubscription::STATUS_ACTIVE,
                'starts_at' => $now,
                'ends_at' => $expiresAt,
            ]);

            $tenant->update([
                'subscription_plan' => $plan->code,
                'subscription_status' => Tenant::SUBSCRIPTION_TRIALING,
                'access_status' => Tenant::ACCESS_ACTIVE,
                'status' => 'active',
            ]);

            app(EntitlementService::class)->clearCache((int) $tenant->id);

            return $sub;
        });
    }

    /**
     * Menangguhkan langganan tenant (Suspended).
     * Memblokir seluruh akses kasir dan operasional.
     */
    public function suspend(Tenant $tenant, string $reason = 'Overdue billing'): TenantSubscription
    {
        return DB::transaction(function () use ($tenant, $reason) {
            /** @var TenantSubscription|null $activeSub */
            // @tenant-bypass: platform_entitlement
            $activeSub = TenantSubscription::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->latest('id')
                ->first();

            if ($activeSub) {
                $activeSub->update([
                    'status' => self::STATUS_SUSPENDED,
                    'notes' => $reason,
                ]);
            } else {
                $defaultPlan = Plan::firstOrCreate(
                    ['code' => 'starter'],
                    ['name' => 'Starter Plan', 'price' => 0]
                );

                // @tenant-bypass: platform_entitlement
                $activeSub = TenantSubscription::withoutGlobalScopes()->create([
                    'tenant_id' => $tenant->id,
                    'plan_id' => $defaultPlan->id,
                    'status' => self::STATUS_SUSPENDED,
                    'starts_at' => Carbon::now(),
                    'metadata' => ['reason' => $reason],
                ]);
            }

            $tenant->update([
                'access_status' => Tenant::ACCESS_SUSPENDED,
                'status' => 'suspended',
            ]);

            app(EntitlementService::class)->clearCache((int) $tenant->id);

            return $activeSub;
        });
    }

    /**
     * Memulihkan kembali status langganan tenant dari penangguhan (Resume).
     */
    public function resume(Tenant $tenant): TenantSubscription
    {
        return DB::transaction(function () use ($tenant) {
            /** @var TenantSubscription|null $sub */
            // @tenant-bypass: platform_entitlement
            $sub = TenantSubscription::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->latest('id')
                ->first();

            if ($sub) {
                $sub->update([
                    'status' => self::STATUS_ACTIVE,
                ]);
            }

            $tenant->update([
                'access_status' => Tenant::ACCESS_ACTIVE,
                'status' => 'active',
            ]);

            return $sub ?? new TenantSubscription;
        });
    }

    /**
     * Transisi status siklus hidup langganan (TRIAL, ACTIVE, GRACE_PERIOD, SUSPENDED, ARCHIVED).
     */
    public function transitionStatus(TenantSubscription $subscription, string $newStatus, string $reason = ''): TenantSubscription
    {
        $normalized = strtolower(trim($newStatus));
        if ($normalized === self::STATUS_SUSPENDED) {
            /** @var Tenant $tenant */
            // @tenant-bypass: platform_entitlement
            $tenant = Tenant::withoutGlobalScopes()->findOrFail($subscription->tenant_id);

            return $this->suspend($tenant, $reason);
        }

        $subscription->update([
            'status' => $normalized,
            'notes' => $reason,
        ]);

        return $subscription;
    }
}
