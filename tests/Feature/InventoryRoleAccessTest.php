<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('mengizinkan role Inventory mengakses modul bahan baku', function () {
    $role = Role::firstOrCreate(['name' => 'Inventory']);
    $user = User::factory()->create(['role_id' => $role->id]);

    $this->actingAs($user)->get(route('inventory.ingredients'))->assertOk();
    $this->actingAs($user)->get(route('inventory.ledger'))->assertOk();
});
