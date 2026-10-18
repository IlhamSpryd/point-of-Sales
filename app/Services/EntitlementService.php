<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Device;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\TenantEntitlementOverride;
use App\Models\TenantSubscription;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\Cache;

class EntitlementService
{
    public const FEATURE_RESTOCK_FORECAST = 'restock_forecast';

    public const FEATURE_OMNICHANNEL = 'omnichannel';

    public const FEATURE_ASYNC_EXPORT = 'async_export';

    public const QUOTA_MAX_STORES = 'max_stores';

    public const QUOTA_MAX_DEVICES_PER_STORE = 'max_devices_per_store';

    public const QUOTA_MAX_EMPLOYEES = 'max_employees';

    /**
     * Memeriksa apakah tenant memiliki hak akses ke suatu fitur berbayar.
     */
    public function canAccessFeature(int $tenantId, string $featureCode): bool
    {
        $cacheKey = "tenant:{$tenantId}:entitlement:{$featureCode}";

        return (bool) Cache::remember($cacheKey, 3600, function () use ($tenantId, $featureCode) {
            // 1. Cek override spesifik tenant
            $override = TenantEntitlementOverride::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('feature_code', $featureCode)
                ->first();

            if ($override) {
                if ($override->expires_at && $override->expires_at->isPast()) {
                    // Override kedaluwarsa, abaikan
                } else {
                    return in_array(strtolower((string) $override->value), ['true', '1', 'yes'], true);
                }
            }

            // 2. Ambil Plan aktif
            $plan = $this->resolveActivePlanForTenant($tenantId);
            if (! $plan) {
                return false;
            }

            // 3. Cek plan entitlements
            $entitlement = PlanEntitlement::where('plan_id', $plan->id)
                ->where('feature_code', $featureCode)
                ->first();

            if (! $entitlement) {
                return false;
            }

            return in_array(strtolower((string) $entitlement->value), ['true', '1', 'yes'], true);
        });
    }

    /**
     * Mengambil batas kuota numerik untuk tenant.
     */
    public function getQuota(int $tenantId, string $quotaCode): int
    {
        $cacheKey = "tenant:{$tenantId}:quota:{$quotaCode}";

        return (int) Cache::remember($cacheKey, 3600, function () use ($tenantId, $quotaCode) {
            // 1. Cek override spesifik tenant
            $override = TenantEntitlementOverride::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('feature_code', $quotaCode)
                ->first();

            if ($override) {
                if (! $override->expires_at || $override->expires_at->isFuture()) {
                    return $this->parseQuotaValue((string) $override->value);
                }
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
     * Memastikan tenant belum melampaui batas kuota cabang.
     *
     * @throws DomainException
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
            throw new DomainException("Batas kuota cabang ({$quota}) untuk paket langganan Anda telah tercapai. Silakan upgrade paket Anda untuk menambah cabang baru.");
        }
    }

    /**
     * Memastikan cabang belum melampaui batas kuota perangkat fisik POS.
     *
     * @throws DomainException
     */
    public function assertCanCreateDevice(int $tenantId, int $storeId): void
    {
        $quota = $this->getQuota($tenantId, self::QUOTA_MAX_DEVICES_PER_STORE);
        if ($quota === PHP_INT_MAX) {
            return;
        }

        $currentCount = Device::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->count();

        if ($currentCount >= $quota) {
            throw new DomainException("Batas kuota perangkat ({$quota}) untuk cabang ini telah tercapai. Silakan upgrade paket langganan Anda untuk menambah perangkat kasir.");
        }
    }

    /**
     * Memastikan tenant belum melampaui batas kuota pengguna/karyawan.
     *
     * @throws DomainException
     */
    public function assertCanInviteEmployee(int $tenantId): void
    {
        $quota = $this->getQuota($tenantId, self::QUOTA_MAX_EMPLOYEES);
        if ($quota === PHP_INT_MAX) {
            return;
        }

        $currentCount = User::where('tenant_id', $tenantId)->count();

        if ($currentCount >= $quota) {
            throw new DomainException("Batas kuota karyawan ({$quota}) untuk paket langganan Anda telah tercapai. Silakan upgrade paket langganan Anda untuk mengundang karyawan baru.");
        }
    }

    /**
     * Membersihkan cache entitlement dan kuota untuk tenant.
     */
    public function clearCache(int $tenantId): void
    {
        $features = [
            self::FEATURE_RESTOCK_FORECAST,
            self::FEATURE_OMNICHANNEL,
            self::FEATURE_ASYNC_EXPORT,
        ];

        $quotas = [
            self::QUOTA_MAX_STORES,
            self::QUOTA_MAX_DEVICES_PER_STORE,
            self::QUOTA_MAX_EMPLOYEES,
        ];

        foreach ($features as $f) {
            Cache::forget("tenant:{$tenantId}:entitlement:{$f}");
        }

        foreach ($quotas as $q) {
            Cache::forget("tenant:{$tenantId}:quota:{$q}");
        }
    }

    /**
     * Menemukan Plan aktif milik tenant dari tabel subscription atau kolom tenant.
     */
    public function resolveActivePlanForTenant(int $tenantId): ?Plan
    {
        /** @var TenantSubscription|null $subscription */
        $subscription = TenantSubscription::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', [TenantSubscription::STATUS_ACTIVE, TenantSubscription::STATUS_TRIALING])
            ->latest('id')
            ->first();

        if ($subscription && $subscription->plan) {
            return $subscription->plan;
        }

        /** @var Tenant|null $tenant */
        $tenant = Tenant::withoutGlobalScopes()->find($tenantId);
        $planCode = $tenant?->subscription_plan ?? 'standard_trial';

        return Plan::where('code', $planCode)->first();
    }

    private function parseQuotaValue(string $val): int
    {
        $normalized = strtolower(trim($val));
        if ($normalized === 'unlimited' || $normalized === '-1') {
            return PHP_INT_MAX;
        }

        return max(0, (int) $val);
    }
}
