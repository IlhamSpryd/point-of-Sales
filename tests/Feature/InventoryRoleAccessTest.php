<?php

use App\Models\Role;
use App\Models\User;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

it('mengizinkan role Inventory mengakses modul bahan baku', function () {
    $role = Role::firstOrCreate(['name' => 'Inventory']);
    $user = User::factory()->create(['role_id' => $role->id]);

    $this->actingAs($user)->get(route('inventory.ingredients'))->assertOk();
    $this->actingAs($user)->get(route('inventory.ledger'))->assertOk();
});
