<?php

declare(strict_types=1);

namespace Tests\Feature\Restaurant;

use App\Models\Role;
use App\Models\Store;
use App\Models\Table;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class TableCreationLengthTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;
    private Store $store;
    private User $user;

    protected function afterSubclassSetUp(): void
    {
        $this->tenant = $this->defaultTenant;
        $this->store = $this->defaultStore;

        $role = Role::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'name' => 'Owner'],
            ['guard_name' => 'web', 'display_name' => 'Owner']
        );

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $role->id,
        ]);
        $this->user->stores()->sync([
            $this->store->id => ['tenant_id' => $this->tenant->id],
        ]);
    }

    public function test_can_create_table_with_long_descriptive_name(): void
    {
        $this->actingAs($this->user);

        $longTableName = 'Meja VIP Eksklusif Gazebo Kolam Ikan Outdoor Lantai 2 #99'; // 57 chars (> 10)

        $response = $this->post(route('tables.store'), [
            'store_id' => $this->store->id,
            'table_name' => $longTableName,
            'capacity' => 8,
            'area' => 'Outdoor',
            'is_active' => true,
            'operational_status' => 'available',
        ]);

        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('tables', [
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'table_name' => $longTableName,
            'capacity' => 8,
        ]);
    }

    public function test_can_create_table_with_exact_100_character_name(): void
    {
        $this->actingAs($this->user);

        $name100 = str_repeat('A', 100);

        $response = $this->post(route('tables.store'), [
            'store_id' => $this->store->id,
            'table_name' => $name100,
            'capacity' => 4,
            'area' => 'Main Dining',
            'is_active' => true,
            'operational_status' => 'available',
        ]);

        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('tables', [
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'table_name' => $name100,
        ]);
    }

    public function test_cannot_create_table_with_over_100_characters(): void
    {
        $this->actingAs($this->user);

        $name101 = str_repeat('B', 101);

        $response = $this->post(route('tables.store'), [
            'store_id' => $this->store->id,
            'table_name' => $name101,
            'capacity' => 4,
            'area' => 'Main Dining',
            'is_active' => true,
            'operational_status' => 'available',
        ]);

        $response->assertSessionHasErrors(['table_name']);
    }
}
