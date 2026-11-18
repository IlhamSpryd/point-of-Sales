<?php

declare(strict_types=1);

namespace App\Modules\Subscription\Contracts;

/**
 * Enterprise contract for tenant subscription entitlements (EXT-01, ADR-113).
 * Strictly separates tenant-level subscription/quota gates from user-level RBAC abilities.
 */
interface EntitlementGateInterface
{
    /**
     * Determines whether the tenant's active subscription plan or override grants access to a feature.
     */
    public function hasFeature(int $tenantId, string $featureKey): bool;

    /**
     * Determines whether the given usage count is within the tenant's plan quota.
     */
    public function withinQuota(int $tenantId, string $quotaKey, int $currentUsage): bool;

    /**
     * Determines whether table management feature is enabled for the tenant and branch.
     */
    public function isTablesEnabled(int $tenantId, ?int $storeId = null): bool;
}
