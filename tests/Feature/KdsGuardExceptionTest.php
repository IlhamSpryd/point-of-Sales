<?php

use App\Livewire\Kds\Board;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class KdsGuardExceptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_claim_item_yang_tidak_ada_tidak_menampilkan_500_error(): void
    {
        $role = Role::firstOrCreate(['name' => 'Barista']);
        $user = User::factory()->create(['role_id' => $role->id]);

        Livewire::actingAs($user)
            ->test(Board::class)
            ->call('claim', 999999) // ID tidak ada
            ->assertHasErrors('kds')
            ->assertOk(); // tidak crash 500
    }
}
