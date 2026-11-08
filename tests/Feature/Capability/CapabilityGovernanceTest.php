<?php

declare(strict_types=1);

namespace Tests\Feature\Capability;

use App\Models\CapabilityDefinition;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\Store;
use App\Models\StoreCapability;
use App\Models\Table;
use App\Models\Tenant;
use App\Models\TenantCapability;
use App\Models\TenantSubscription;
use App\Services\Capability\CapabilityManager;
use App\Services\Context\TenantContext;
use App\Services\EntitlementService;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CapabilityGovernanceTest extends TestCase
{
    use DatabaseTransactions;

    protected bool $optOutFromDefaultTenant = true;

    private Tenant $tenant;
    private Store $store;
    private CapabilityManager $capabilityManager;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = Tenant::create([
            'name' => 'Apeiron Capability Tenant',
            'slug' => 'apeiron-cap-'.uniqid(),
            'status' => 'active',
            'country_code' => 'ID',
            'config_version' => 1,
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Flagship Store',
            'is_active' => true,
        ]);

        $this->capabilityManager = app(CapabilityManager::class);
    }

    /**
     * Test Matrix #3: Capability Entitlement Bypass Prevention
     * A tenant with a basic plan (max_stores = 1) cannot access multi_branch capability.
     */
    public function test_unentitled_capability_is_rejected_at_stage_a(): void
    {
        // Create plan with max_stores = 1
        $plan = Plan::create([
            'name' => 'Basic Plan',
            'code' => 'basic_plan_'.uniqid(),
            'price' => 100000,
            'is_active' => true,
        ]);

        PlanEntitlement::create([
            'plan_id' => $plan->id,
            'feature_code' => EntitlementService::QUOTA_MAX_STORES,
            'value' => '1',
        ]);

        TenantSubscription::create([
            'tenant_id' => $this->tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);

        $resolution = $this->capabilityManager->resolve('core.multi_branch');

        $this->assertFalse($resolution['is_eligible']);
        $this->assertFalse($resolution['is_active']);
        $this->assertSame('ERR_ENTITLEMENT_NOT_OWNED', $resolution['status_code']);
    }

    /**
     * Test Matrix #4: Dependency Check Failure
     * `fnb.waiter_ordering` depends on `fnb.table_management`.
     * If `fnb.table_management` is disabled, waiter ordering resolution must fail.
     */
    public function test_missing_dependency_fails_with_err_dependency_unsatisfied(): void
    {
        // Disable parent capability
        TenantCapability::withoutGlobalScopes()->updateOrInsert(
            ['tenant_id' => $this->tenant->id, 'capability_code' => 'fnb.table_management'],
            ['is_enabled' => false, 'updated_at' => now()]
        );

        $resolution = $this->capabilityManager->resolve('fnb.waiter_ordering');

        $this->assertFalse($resolution['is_eligible']);
        $this->assertFalse($resolution['is_active']);
        $this->assertSame('ERR_DEPENDENCY_UNSATISFIED', $resolution['status_code']);
    }

    /**
     * Mutual Exclusion / Incompatibility Check
     */
    public function test_mutual_exclusion_fails_with_incompatible_error(): void
    {
        // Register mutually incompatible capabilities
        CapabilityDefinition::updateOrInsert(
            ['code' => 'fnb.dine_in_layout'],
            [
                'name' => 'Dine In Layout',
                'industry' => 'fnb',
                'incompatible_with' => json_encode(['fnb.pure_cloud_kitchen']),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // Enable conflicting feature
        TenantCapability::withoutGlobalScopes()->updateOrInsert(
            ['tenant_id' => $this->tenant->id, 'capability_code' => 'fnb.pure_cloud_kitchen'],
            ['is_enabled' => true, 'updated_at' => now()]
        );

        $resolution = $this->capabilityManager->resolve('fnb.dine_in_layout');

        $this->assertFalse($resolution['is_eligible']);
        $this->assertSame('ERR_INCOMPATIBLE_MUTUAL_EXCLUSION', $resolution['status_code']);
    }

    /**
     * Stage B: Store-level override takes precedence over tenant default
     */
    public function test_store_override_takes_precedence_over_tenant_default(): void
    {
        // Tenant enables barcode scanning by default
        TenantCapability::withoutGlobalScopes()->updateOrInsert(
            ['tenant_id' => $this->tenant->id, 'capability_code' => 'retail.barcode_scanning'],
            ['is_enabled' => true, 'updated_at' => now()]
        );

        // Store explicitly disables barcode scanning
        StoreCapability::withoutGlobalScopes()->updateOrInsert(
            ['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'capability_code' => 'retail.barcode_scanning'],
            ['is_enabled' => false, 'updated_at' => now()]
        );

        $resolution = $this->capabilityManager->resolve('retail.barcode_scanning', storeId: $this->store->id);

        $this->assertTrue($resolution['is_eligible']);
        $this->assertFalse($resolution['is_active']);
        $this->assertSame('OK_STORE_OVERRIDE_DISABLED', $resolution['status_code']);
    }

    /**
     * Test Matrix #12: Safe Deactivation Safeguard
     * Cannot deactivate table management when tables are currently occupied.
     */
    public function test_safe_deactivation_prevents_disabling_table_management_when_tables_are_occupied(): void
    {
        // Create occupied table
        Table::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'table_name' => 'Meja VIP 1',
            'capacity' => 4,
            'operational_status' => 'occupied',
            'is_active' => true,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('tables are currently occupied');

        $this->capabilityManager->disableCapability($this->tenant->id, 'fnb.table_management');
    }

    /**
     * Configuration Versioning: Config version increments on capability change
     */
    public function test_capability_mutation_bumps_config_version(): void
    {
        $initialVersion = (int) $this->tenant->refresh()->config_version;

        $this->capabilityManager->enableCapability($this->tenant->id, 'retail.barcode_scanning');

        $newVersion = (int) $this->tenant->refresh()->config_version;
        $this->assertGreaterThan($initialVersion, $newVersion);
    }

    /**
     * Safe Deactivation: Prevents deactivation when dependent capabilities are active.
     */
    public function test_safe_deactivation_prevents_disabling_when_dependent_capability_is_active(): void
    {
        // Both table_management and waiter_ordering are enabled (waiter_ordering depends on table_management)
        TenantCapability::withoutGlobalScopes()->updateOrInsert(
            ['tenant_id' => $this->tenant->id, 'capability_code' => 'fnb.table_management'],
            ['is_enabled' => true, 'updated_at' => now()]
        );
        TenantCapability::withoutGlobalScopes()->updateOrInsert(
            ['tenant_id' => $this->tenant->id, 'capability_code' => 'fnb.waiter_ordering'],
            ['is_enabled' => true, 'updated_at' => now()]
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage("active capability 'fnb.waiter_ordering'");

        $this->capabilityManager->disableCapability($this->tenant->id, 'fnb.table_management');
    }
}
