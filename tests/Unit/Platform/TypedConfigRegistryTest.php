<?php

declare(strict_types=1);

namespace Tests\Unit\Platform;

use App\Core\Context\TenantContext;
use App\Platform\Authorization\Scope;
use App\Platform\Configuration\ConfigDefinition;
use App\Platform\Configuration\TypedConfigRegistry;
use App\Platform\Configuration\TypedConfigService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class TypedConfigRegistryTest extends TestCase
{
    private TypedConfigService $service;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        TypedConfigRegistry::reset();
        TypedConfigRegistry::boot();

        $this->context = new TenantContext;
        $this->context->setTenantId(1);
        $this->context->setCompanyId(10);
        $this->context->setStoreId(100);

        $this->service = new TypedConfigService($this->context);
    }

    public function test_core_canonical_settings_are_registered(): void
    {
        $expectedKeys = [
            'pos.allow_negative_stock',
            'pos.auto_lock_minutes',
            'pos.require_shift_open',
            'tax.default_ppn_rate',
            'pricing.reservation_ttl_minutes',
            'finance.enforce_maker_checker',
            'inventory.default_costing_method',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertTrue(TypedConfigRegistry::has($key), "Config key '{$key}' should be registered.");
            $def = TypedConfigRegistry::get($key);
            $this->assertSame($key, $def->key);
            $this->assertNotEmpty($def->description);
            $this->assertContains($def->type, ConfigDefinition::VALID_TYPES);
        }
    }

    public function test_typed_config_service_returns_defaults(): void
    {
        $this->assertFalse($this->service->get('pos.allow_negative_stock'));
        $this->assertSame(15, $this->service->get('pos.auto_lock_minutes'));
        $this->assertTrue($this->service->get('pos.require_shift_open'));
        $this->assertSame('11.00', $this->service->get('tax.default_ppn_rate'));
        $this->assertSame(30, $this->service->get('pricing.reservation_ttl_minutes'));
        $this->assertTrue($this->service->get('finance.enforce_maker_checker'));
        $this->assertSame('MOVING_AVERAGE', $this->service->get('inventory.default_costing_method'));
    }

    public function test_type_casting_and_validation(): void
    {
        // Valid cast
        $this->assertSame(20, $this->service->validate('pos.auto_lock_minutes', '20'));
        $this->assertTrue($this->service->validate('pos.allow_negative_stock', '1'));
        $this->assertSame('12.50', $this->service->validate('tax.default_ppn_rate', 12.5));

        // Invalid cast raises exception
        $this->expectException(InvalidArgumentException::class);
        $this->service->validate('pos.auto_lock_minutes', 'not-a-number');
    }

    public function test_unknown_key_throws_invalid_argument_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->get('non_existent.random_key');
    }

    public function test_setting_and_retrieving_runtime_override(): void
    {
        $this->service->set('pos.auto_lock_minutes', 60);
        $this->assertSame(60, $this->service->get('pos.auto_lock_minutes'));

        // Setting custom key
        $customDef = new ConfigDefinition(
            key: 'custom.feature_flag',
            type: 'bool',
            default: false,
            scope: Scope::TENANT,
            ownerModule: 'Platform',
            description: 'Custom feature toggle'
        );
        TypedConfigRegistry::register($customDef);

        $this->assertFalse($this->service->get('custom.feature_flag'));
        $this->service->set('custom.feature_flag', true);
        $this->assertTrue($this->service->get('custom.feature_flag'));
    }
}
