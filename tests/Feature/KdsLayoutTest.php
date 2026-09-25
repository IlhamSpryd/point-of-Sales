<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KdsLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_kds_route_renders_kds_dark_theme_layout_not_admin_layout(): void
    {
        $role = Role::firstOrCreate(['name' => 'Barista']);
        $user = User::factory()->create(['role_id' => $role->id]);

        $response = $this->actingAs($user)->get(route('kds.index'));

        $response->assertOk();
        $response->assertSee('data-theme="kds-dark"', false);
        $response->assertDontSee('id="main-sidebar"', false); // bukti TIDAK memakai layouts.app (admin)
    }
}
