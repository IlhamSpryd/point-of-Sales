<?php

namespace Tests\Feature;

use App\Livewire\Concerns\RequiresTenantContext;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Component;
use Livewire\Livewire;
use Tests\TestCase;

class DummyLivewireComponent extends Component
{
    use RequiresTenantContext;

    public function assertContext()
    {
        $context = app(TenantContext::class);
        if (! $context->hasTenant()) {
            throw new \RuntimeException('No tenant context inside Livewire update!');
        }
    }

    public function render()
    {
        return '<div>Dummy</div>';
    }
}

class TenantContextLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected bool $optOutFromDefaultTenant = true;

    public function test_tenant_context_persists_in_livewire_update()
    {
        $tenant = Tenant::create(['name' => 'Test Tenant']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'is_active' => true]);

        Livewire::component('dummy', DummyLivewireComponent::class);

        // With the RequiresTenantContext concern, we can just use normal Livewire testing!
        $component = Livewire::actingAs($user)
            ->test('dummy')
            ->call('assertContext');

        $component->assertOk();
    }

    public function test_inactive_user_is_denied_on_livewire_update()
    {
        $tenant = Tenant::create(['name' => 'Test Tenant']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'is_active' => true]);

        Livewire::component('dummy', DummyLivewireComponent::class);

        $component = Livewire::actingAs($user)->test('dummy');

        // User becomes inactive
        $user->update(['is_active' => false]);

        // Next Livewire update should fail
        $component->call('assertContext')
            ->assertForbidden();
    }

    public function test_queue_jobs_clear_scoped_instances()
    {
        $context = app(TenantContext::class);
        $context->setTenantId(999);
        $this->assertTrue($context->hasTenant());

        // Simulate queue job resetting scopes
        app()->forgetScopedInstances();

        $newContext = app(TenantContext::class);
        $this->assertFalse($newContext->hasTenant());
        $this->assertNotSame($context, $newContext);
    }
}
