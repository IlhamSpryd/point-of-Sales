<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Livewire\Kasir\CreateOrder;
use App\Models\Device;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionClass;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

class CreateOrderPublicStateLeakTest extends TestCase
{
    use RefreshDatabase;
    use TwoTenantFixture;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $context = app(TenantContext::class);
        $context->runWithoutTenant(function () {
            $this->setupTwoTenants();
        });

        $context->runAs($this->tenantA->id, $this->storeA->id, function () {
            Device::create([
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA->id,
                'device_name' => 'POS Terminal 1',
                'device_code' => 'POS-T1',
                'device_type' => Device::TYPE_POS_TERMINAL,
                'device_fingerprint' => 'fp-pos-1',
                'status' => Device::STATUS_PAIRED,
                'is_active' => true,
            ]);
        });
    }

    public function test_supervisor_pin_is_not_exposed_in_public_state_or_snapshot(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $reflection = new ReflectionClass(CreateOrder::class);

        // 1. Assert public properties manualDiscountSupervisorPin and supervisorPinInput DO NOT exist
        $this->assertFalse(
            $reflection->hasProperty('manualDiscountSupervisorPin') && $reflection->getProperty('manualDiscountSupervisorPin')->isPublic(),
            'CreateOrder must not have a public manualDiscountSupervisorPin property.'
        );

        $this->assertFalse(
            $reflection->hasProperty('supervisorPinInput') && $reflection->getProperty('supervisorPinInput')->isPublic(),
            'CreateOrder must not have a public supervisorPinInput property.'
        );

        // 2. Mount CreateOrder and check public state snapshot
        $this->actingAs($this->userAOwner);

        $testable = Livewire::test(CreateOrder::class);

        // Check snapshot data
        $snapshot = $testable->snapshot;
        $data = $snapshot['data'] ?? [];

        $this->assertArrayNotHasKey('manualDiscountSupervisorPin', $data);
        $this->assertArrayNotHasKey('supervisorPinInput', $data);

        // 3. Call applyManualDiscount with a PIN parameter and verify the PIN is never saved to component properties
        $testable->set('manualDiscountAmountInput', 5000)
            ->set('manualDiscountReason', 'Test Discount')
            ->call('applyManualDiscount', '123456');

        $updatedSnapshot = $testable->snapshot;
        $updatedData = $updatedSnapshot['data'] ?? [];

        $this->assertArrayNotHasKey('manualDiscountSupervisorPin', $updatedData);
        $this->assertArrayNotHasKey('supervisorPinInput', $updatedData);
    }
}
