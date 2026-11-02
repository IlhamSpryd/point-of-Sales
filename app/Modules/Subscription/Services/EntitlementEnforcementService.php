<?php

declare(strict_types=1);

namespace App\Modules\Subscription\Services;

use App\Models\Device;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\TenantEntitlementOverride;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Modules\Subscription\Exceptions\PlanLimitExceededException;
use Illuminate\Support\Facades\Cache;

class EntitlementEnforcementService
{
    public const QUOTA_MAX_STORES = 'max_stores';

    public const QUOTA_MAX_DEVICES = 'max_devices';

    public const QUOTA_MAX_USERS = 'max_users';

    /**
     * Resolusi Plan aktif untuk tenant.
     */
    public function resolveActivePlanForTenant(int $tenantId): ?Plan
    {
        /** @var TenantSubscription|null $subscription */
        $subscription = TenantSubscription::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['active', 'trial', 'grace_period', 'ACTIVE', 'TRIAL', 'GRACE_PERIOD'])
            ->latest('id')
            ->first();

        if ($subscription && $subscription->plan instanceof Plan) {
            return $subscription->plan;
        }

        /** @var Tenant|null $tenant */
        $tenant = Tenant::withoutGlobalScopes()->find($tenantId);
        if ($tenant && ! empty($tenant->plan_code)) {
            return Plan::where('code', $tenant->plan_code)->first();
        }

        return Plan::where('code', 'starter')->first();
    }

    /**
     * Mengambil batas kuota numerik untuk tenant berdasarkan Plan atau Override.
     */
    public function getQuota(int $tenantId, string $quotaCode): int
    {
        $cacheKey = "tenant:{$tenantId}:entitlement_quota:{$quotaCode}";

        return (int) Cache::remember($cacheKey, 60, function () use ($tenantId, $quotaCode) {
            // 1. Cek override spesifik tenant
            $override = TenantEntitlementOverride::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('feature_code', $quotaCode)
                ->first();

            if ($override && (! $override->expires_at || $override->expires_at->isFuture())) {
                return $this->parseQuotaValue((string) $override->value);
            }

            // 2. Ambil Plan aktif
            $plan = $this->resolveActivePlanForTenant($tenantId);
            if (! $plan) {
                return 1;
            }

            // 3. Cek plan entitlements
            $entitlement = PlanEntitlement::where('plan_id', $plan->id)
                ->where('feature_code', $quotaCode)
                ->first();

            if (! $entitlement) {
                return 1;
            }

            return $this->parseQuotaValue((string) $entitlement->value);
        });
    }

    /**
     * Memastikan tenant belum melampaui batas kuota cabang (max_stores).
     *
     * @throws PlanLimitExceededException
     */
    public function assertCanCreateStore(int $tenantId): void
    {
        $quota = $this->getQuota($tenantId, self::QUOTA_MAX_STORES);
        if ($quota === PHP_INT_MAX) {
            return;
        }

        $currentCount = Store::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->count();

        if ($currentCount >= $quota) {
            throw PlanLimitExceededException::forQuota('cabang fisik (store)', $quota);
        }
    }

    /**
     * Memastikan tenant belum melampaui batas kuota kasir/perangkat fisik (max_devices).
     *
     * @throws PlanLimitExceededException
     */
    public function assertCanCreateDevice(int $tenantId, int $storeId): void
    {
        $quota = $this->getQuota($tenantId, self::QUOTA_MAX_DEVICES);
        if ($quota === PHP_INT_MAX) {
            return;
        }

        $currentCount = Device::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->count();

        if ($currentCount >= $quota) {
            throw PlanLimitExceededException::forQuota('perangkat kasir (device)', $quota);
        }
    }

    /**
     * Memastikan tenant belum melampaui batas kuota pengguna (max_users).
     *
     * @throws PlanLimitExceededException
     */
    public function assertCanCreateUser(int $tenantId): void
    {
        $quota = $this->getQuota($tenantId, self::QUOTA_MAX_USERS);
        if ($quota === PHP_INT_MAX) {
            return;
        }

        $currentCount = User::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->count();

        if ($currentCount >= $quota) {
            throw PlanLimitExceededException::forQuota('pengguna / staf (user)', $quota);
        }
    }

    private function parseQuotaValue(string $val): int
    {
        $trimmed = strtolower(trim($val));
        if ($trimmed === 'unlimited' || $trimmed === '-1' || $trimmed === 'infinity') {
            return PHP_INT_MAX;
        }

        return max(0, (int) $val);
    }
}
