<?php

declare(strict_types=1);

namespace Tests\Arch;

use App\Modules\Subscription\Contracts\EntitlementGateInterface;
use App\Platform\Authorization\AbilityRegistry;
use App\Platform\Configuration\ConfigDefinition;
use App\Platform\Configuration\TypedConfigRegistry;
use ReflectionClass;
use Tests\TestCase;

/**
 * Architecture Guardrail: Configuration Registry Governance and Entitlement Isolation (EXT-01, EXT-02, ADR-112, ADR-113).
 */
class GovernanceAndConfigContractTest extends TestCase
{
    private const ALLOWED_OWNER_MODULES = [
        'Catalog',
        'Customers',
        'Finance',
        'Integration',
        'Inventory',
        'Manufacturing',
        'Organization',
        'Pricing',
        'Purchasing',
        'Reporting',
        'Sales',
        'Subscription',
        'Workforce',
        'Platform',
    ];

    public function test_typed_configuration_registry_definitions_satisfy_governance_rules(): void
    {
        $definitions = TypedConfigRegistry::all();
        $this->assertNotEmpty($definitions, 'TypedConfigRegistry must contain registered configurations.');

        foreach ($definitions as $key => $definition) {
            $this->assertSame($key, $definition->key);
            $this->assertContains(
                $definition->type,
                ConfigDefinition::VALID_TYPES,
                "Config '{$key}' has invalid type '{$definition->type}'."
            );

            $this->assertContains(
                $definition->ownerModule,
                self::ALLOWED_OWNER_MODULES,
                "Config '{$key}' has invalid ownerModule '{$definition->ownerModule}'. Must be one of 13 Bounded Contexts or Platform."
            );

            $this->assertNotEmpty(
                trim($definition->description),
                "Config '{$key}' must have a non-empty description."
            );
        }
    }

    public function test_entitlement_gate_is_bound_and_strictly_isolated_from_ability_registry(): void
    {
        // 1. EntitlementGateInterface must be bound in the container
        $this->assertTrue(
            app()->bound(EntitlementGateInterface::class),
            'EntitlementGateInterface must be registered in the service container.'
        );

        $instance = app(EntitlementGateInterface::class);
        $this->assertInstanceOf(EntitlementGateInterface::class, $instance);

        // 2. Separation of concerns (ADR-113): EntitlementGateInterface must not extend or inherit AbilityRegistry
        $entitlementRef = new ReflectionClass(EntitlementGateInterface::class);
        $this->assertFalse(
            $entitlementRef->isSubclassOf(AbilityRegistry::class),
            'EntitlementGateInterface must NOT extend AbilityRegistry. Entitlements and Abilities must remain decoupled.'
        );

        // 3. AbilityRegistry must not have methods checking plan quotas
        $abilityRef = new ReflectionClass(AbilityRegistry::class);
        $this->assertFalse(
            $abilityRef->hasMethod('withinQuota'),
            'AbilityRegistry must not handle subscription quotas. Quotas belong strictly to EntitlementGateInterface.'
        );
    }
}
