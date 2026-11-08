<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Models\Device;
use App\Models\OperationalUnit;
use App\Models\Store;
use App\Models\Tenant;
use App\Services\Context\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class OperationalUnitHierarchyTest extends TestCase
{
    use DatabaseTransactions;

    protected bool $optOutFromDefaultTenant = true;

    private Tenant $tenantA;
    private Tenant $tenantB;
    private Store $storeA;
    private Store $storeB;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenantA = Tenant::create([
            'name' => 'Tenant Enterprise Alpha',
            'slug' => 'tenant-alpha-'.uniqid(),
            'status' => 'active',
            'country_code' => 'ID',
        ]);

        $this->tenantB = Tenant::create([
            'name' => 'Tenant Enterprise Beta',
            'slug' => 'tenant-beta-'.uniqid(),
            'status' => 'active',
            'country_code' => 'ID',
        ]);

        app(TenantContext::class)->setTenantId($this->tenantA->id);

        $this->storeA = Store::withoutTenantScope()->create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Store Alpha Central',
            'is_active' => true,
        ]);

        app(TenantContext::class)->runAs($this->tenantB->id, null, function () {
            $this->storeB = Store::withoutTenantScope()->create([
                'tenant_id' => $this->tenantB->id,
                'name' => 'Store Beta Central',
                'is_active' => true,
            ]);
        });
    }

    /**
     * Test: Automatic implicit default unit for small businesses
     */
    public function test_operational_unit_is_created_automatically_on_store_creation(): void
    {
        $defaultUnit = OperationalUnit::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantA->id)
            ->where('store_id', $this->storeA->id)
            ->where('is_default', true)
            ->first();

        $this->assertNotNull($defaultUnit);
        $this->assertSame('MAIN', $defaultUnit->code);
        $this->assertTrue($defaultUnit->is_active);
    }

    /**
     * Test: Device registers under operational unit
     */
    public function test_device_can_be_associated_with_operational_unit(): void
    {
        $unit = OperationalUnit::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantA->id)
            ->where('store_id', $this->storeA->id)
            ->first();

        $device = Device::withoutTenantScope()->create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'operational_unit_id' => $unit->id,
            'device_code' => 'POS-DEV-'.uniqid(),
            'device_name' => 'Front Register 1',
            'device_type' => Device::TYPE_POS_TERMINAL,
            'is_active' => true,
        ]);

        $this->assertNotNull($device);
        $this->assertSame($unit->id, $device->operational_unit_id);
        $this->assertSame($unit->name, $device->operationalUnit->name);
    }

    /**
     * Test Matrix #10 & Negative Test 4:
     * Cross-tenant operational unit reference violation is rejected at database constraint level.
     */
    public function test_composite_foreign_key_prevents_cross_tenant_store_unit_association(): void
    {
        // Direct DB::table insert bypasses Eloquent traits to test MySQL InnoDB foreign key constraint directly
        $this->expectException(QueryException::class);

        \Illuminate\Support\Facades\DB::table('operational_units')->insert([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeB->id, // Cross-tenant store from Tenant B!
            'name' => 'Hacked Cross-Tenant Unit',
            'code' => 'HACK',
            'unit_type' => 'fnb_kitchen',
            'is_default' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
