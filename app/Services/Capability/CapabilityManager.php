<?php

declare(strict_types=1);

namespace App\Services\Capability;

use App\Enums\OrderStatus;
use App\Models\CapabilityDefinition;
use App\Models\Order;
use App\Models\StoreCapability;
use App\Models\Table;
use App\Models\Tenant;
use App\Models\TenantCapability;
use App\Services\Context\TenantContext;
use App\Services\EntitlementService;
use DomainException;
use Illuminate\Support\Facades\Cache;

/**
 * Capability Governance Engine (Section 3 of Blueprint)
 *
 * Implements 2-Stage Governance:
 * Stage A: Eligibility Evaluation (Platform Support, SaaS Entitlement, Dependencies, Incompatibilities)
 * Stage B: Hierarchical Activation Resolution (Device -> Operational Unit -> Store -> Tenant Default)
 */
class CapabilityManager
{
    public function __construct(
        protected TenantContext $tenantContext,
        protected EntitlementService $entitlementService
    ) {}

    /**
     * Resolves whether a capability is actively available for execution in the given context.
     *
     * @return array{
     *     is_active: bool,
     *     is_eligible: bool,
     *     status_code: string,
     *     reason: string
     * }
     */
    public function resolve(
        string $capabilityCode,
        ?int $storeId = null,
        ?int $operationalUnitId = null,
        ?int $deviceId = null
    ): array {
        $tenantId = $this->tenantContext->requireTenantId();

        // 1. Stage A: Eligibility Evaluation
        $eligibility = $this->evaluateEligibility($tenantId, $capabilityCode);
        if (! $eligibility['is_eligible']) {
            return [
                'is_active' => false,
                'is_eligible' => false,
                'status_code' => $eligibility['status_code'],
                'reason' => $eligibility['reason'],
            ];
        }

        // 2. Stage B: Activation Resolution (Device -> Unit -> Store -> Tenant Default)
        $storeId = $storeId ?? $this->tenantContext->getStoreId();

        // Check Store-level override if store is specified
        if ($storeId !== null) {
            $storeOverride = StoreCapability::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('store_id', $storeId)
                ->where('capability_code', $capabilityCode)
                ->first();

            if ($storeOverride !== null) {
                return [
                    'is_active' => (bool) $storeOverride->is_enabled,
                    'is_eligible' => true,
                    'status_code' => $storeOverride->is_enabled ? 'OK_STORE_OVERRIDE_ENABLED' : 'OK_STORE_OVERRIDE_DISABLED',
                    'reason' => $storeOverride->is_enabled ? 'Explicitly enabled at branch level.' : 'Explicitly disabled at branch level.',
                ];
            }
        }

        // Check Tenant Default Activation
        $tenantCap = TenantCapability::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('capability_code', $capabilityCode)
            ->first();

        $isActive = $tenantCap ? (bool) $tenantCap->is_enabled : false;

        return [
            'is_active' => $isActive,
            'is_eligible' => true,
            'status_code' => $isActive ? 'OK_INHERITED_TENANT_DEFAULT' : 'OK_TENANT_DEFAULT_DISABLED',
            'reason' => $isActive ? 'Active per tenant default subscription.' : 'Disabled in tenant configuration.',
        ];
    }

    /**
     * Stage A: Evaluates hard eligibility rules (Fail-Closed).
     *
     * @return array{is_eligible: bool, status_code: string, reason: string}
     */
    public function evaluateEligibility(int $tenantId, string $capabilityCode): array
    {
        // 1. Platform Support Check
        $definition = CapabilityDefinition::where('code', $capabilityCode)
            ->where('is_active', true)
            ->first();

        if (! $definition) {
            return [
                'is_eligible' => false,
                'status_code' => 'ERR_CAPABILITY_NOT_FOUND',
                'reason' => "Capability '{$capabilityCode}' is not registered on the platform.",
            ];
        }

        // 2. SaaS Entitlement Check
        $entitlementFeatureMap = [
            'fnb.table_management' => EntitlementService::FEATURE_TABLE_MANAGEMENT,
            'core.multi_branch' => EntitlementService::QUOTA_MAX_STORES,
            'fnb.self_order' => EntitlementService::FEATURE_SELF_ORDER,
        ];

        if (isset($entitlementFeatureMap[$capabilityCode])) {
            $mappedFeature = $entitlementFeatureMap[$capabilityCode];
            if ($mappedFeature === EntitlementService::QUOTA_MAX_STORES) {
                $maxStores = $this->entitlementService->getQuota($tenantId, EntitlementService::QUOTA_MAX_STORES);
                if ($maxStores <= 1) {
                    return [
                        'is_eligible' => false,
                        'status_code' => 'ERR_ENTITLEMENT_NOT_OWNED',
                        'reason' => 'Tenant subscription plan does not entitle multi-branch capability.',
                    ];
                }
            } else {
                $hasEntitlement = $this->entitlementService->canAccessFeature($tenantId, $mappedFeature);
                if (! $hasEntitlement) {
                    return [
                        'is_eligible' => false,
                        'status_code' => 'ERR_ENTITLEMENT_NOT_OWNED',
                        'reason' => "Tenant plan does not entitle feature '{$mappedFeature}'.",
                    ];
                }
            }
        }

        // 3. Dependency Check (Topological evaluation)
        if (! empty($definition->dependencies)) {
            foreach ($definition->dependencies as $requiredCode) {
                $parentResolution = $this->resolve((string) $requiredCode);
                if (! $parentResolution['is_active']) {
                    return [
                        'is_eligible' => false,
                        'status_code' => 'ERR_DEPENDENCY_UNSATISFIED',
                        'reason' => "Missing required active dependency: '{$requiredCode}'.",
                    ];
                }
            }
        }

        // 4. Compatibility Check (Mutual exclusion)
        if (! empty($definition->incompatible_with)) {
            foreach ($definition->incompatible_with as $conflictingCode) {
                $conflict = TenantCapability::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('capability_code', (string) $conflictingCode)
                    ->where('is_enabled', true)
                    ->exists();

                if ($conflict) {
                    return [
                        'is_eligible' => false,
                        'status_code' => 'ERR_INCOMPATIBLE_MUTUAL_EXCLUSION',
                        'reason' => "Capability conflict: mutually incompatible with active feature '{$conflictingCode}'.",
                    ];
                }
            }
        }

        return [
            'is_eligible' => true,
            'status_code' => 'OK_ELIGIBLE',
            'reason' => 'All platform, entitlement, and dependency requirements satisfied.',
        ];
    }

    /**
     * Activates a capability at the tenant level.
     */
    public function enableCapability(int $tenantId, string $capabilityCode, ?array $config = null): void
    {
        $eligibility = $this->evaluateEligibility($tenantId, $capabilityCode);
        if (! $eligibility['is_eligible']) {
            throw new DomainException("Cannot enable capability: {$eligibility['reason']} ({$eligibility['status_code']})");
        }

        TenantCapability::withoutGlobalScopes()->updateOrInsert(
            ['tenant_id' => $tenantId, 'capability_code' => $capabilityCode],
            [
                'is_enabled' => true,
                'configuration' => $config ? json_encode($config) : null,
                'updated_at' => now(),
            ]
        );

        $this->bumpConfigVersionAndClearCache($tenantId);
    }

    /**
     * Deactivates a capability safely, executing invariant safety checks first.
     */
    public function disableCapability(int $tenantId, string $capabilityCode): void
    {
        // Safety Assertion Checks
        $this->assertSafeToDeactivate($tenantId, $capabilityCode);

        TenantCapability::withoutGlobalScopes()->updateOrInsert(
            ['tenant_id' => $tenantId, 'capability_code' => $capabilityCode],
            [
                'is_enabled' => false,
                'updated_at' => now(),
            ]
        );

        $this->bumpConfigVersionAndClearCache($tenantId);
    }

    /**
     * Asserts that deactivating a capability does not leave in-flight transactions or states in inconsistent conditions.
     */
    public function assertSafeToDeactivate(int $tenantId, string $capabilityCode): void
    {
        // 1. Dependent Capabilities Check (Topological Safety Guard)
        $activeCapabilities = TenantCapability::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_enabled', true)
            ->where('capability_code', '!=', $capabilityCode)
            ->pluck('capability_code');

        if ($activeCapabilities->isNotEmpty()) {
            $definitions = CapabilityDefinition::whereIn('code', $activeCapabilities)->get();
            foreach ($definitions as $def) {
                $deps = (array) ($def->dependencies ?? []);
                if (in_array($capabilityCode, $deps, true)) {
                    throw new DomainException(
                        "Cannot disable '{$capabilityCode}': active capability '{$def->code}' ({$def->name}) depends on it. Disable dependent capabilities first."
                    );
                }
            }
        }

        // 2. Domain-Specific In-Flight Business Process Guards
        if ($capabilityCode === 'fnb.table_management') {
            // Check if there are tables marked occupied
            $occupiedTables = Table::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('operational_status', 'occupied')
                ->count();

            if ($occupiedTables > 0) {
                throw new DomainException("Cannot disable table management: {$occupiedTables} tables are currently occupied. Clear or checkout tables first.");
            }

            // Check if there are active unpaid table orders
            $openTableOrders = Order::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereNotNull('table_id')
                ->whereIn('order_status', [OrderStatus::Pending])
                ->count();

            if ($openTableOrders > 0) {
                throw new DomainException("Cannot disable table management: {$openTableOrders} orders are currently open on dining tables.");
            }
        }

        if ($capabilityCode === 'fnb.kitchen_display') {
            $activePrepItems = \Illuminate\Support\Facades\DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.tenant_id', $tenantId)
                ->whereIn('order_items.kitchen_prep_status', ['queued', 'preparing'])
                ->count();

            if ($activePrepItems > 0) {
                throw new DomainException("Cannot disable kitchen display: {$activePrepItems} order items are currently in kitchen prep queue.");
            }
        }
    }

    protected function bumpConfigVersionAndClearCache(int $tenantId): void
    {
        Tenant::withoutGlobalScopes()->where('id', $tenantId)->increment('config_version');

        Cache::forget("tenant_{$tenantId}_capabilities");
    }
}
