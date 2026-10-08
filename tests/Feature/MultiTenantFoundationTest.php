<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiTenantFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $optOutFromDefaultTenant = true;

    public function test_tenant_has_stores()
    {
        $tenant = Tenant::create(['name' => 'HQ Corp']);
        $store = Store::create([
            'tenant_id' => $tenant->id,
            'name' => 'Main Branch',
        ]);

        $this->assertEquals(1, $tenant->stores()->count());
        $this->assertEquals('Main Branch', $tenant->stores()->first()->name);
    }

    public function test_store_belongs_to_tenant()
    {
        $tenant = Tenant::create(['name' => 'HQ Corp']);
        $store = Store::create([
            'tenant_id' => $tenant->id,
            'name' => 'Main Branch',
        ]);

        $this->assertEquals('HQ Corp', $store->tenant->name);
    }

    public function test_user_belongs_to_tenant()
    {
        $tenant = Tenant::create(['name' => 'HQ Corp']);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $this->assertEquals('HQ Corp', $user->tenant->name);
        $this->assertEquals(1, $tenant->users()->count());
    }

    public function test_user_can_access_multiple_stores_through_user_stores()
    {
        $tenant = Tenant::create(['name' => 'HQ Corp']);
        $store1 = Store::create(['tenant_id' => $tenant->id, 'name' => 'Store 1']);
        $store2 = Store::create(['tenant_id' => $tenant->id, 'name' => 'Store 2']);

        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $user->stores()->attach([$store1->id, $store2->id]);

        $this->assertEquals(2, $user->stores()->count());
        $this->assertContains('Store 1', $user->stores->pluck('name')->toArray());
        $this->assertContains('Store 2', $user->stores->pluck('name')->toArray());
    }

    public function test_duplicate_user_store_assignment_is_rejected()
    {
        $tenant = Tenant::create(['name' => 'HQ Corp']);
        $store = Store::create(['tenant_id' => $tenant->id, 'name' => 'Store 1']);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $user->stores()->attach($store->id);

        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23000'); // Integrity constraint violation

        // Attempting to attach the same store again should fail due to unique constraint
        $user->stores()->attach($store->id);
    }
}
