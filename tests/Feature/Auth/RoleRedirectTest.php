<?php

use App\Models\Role;
use App\Models\User;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

it('mengarahkan setiap role ke halaman yang benar setelah login', function (string $roleName, string $expectedRoute) {
    $role = Role::firstOrCreate(['name' => $roleName]);
    $user = User::factory()->create(['role_id' => $role->id]);

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route($expectedRoute, absolute: false));
})->with([
    ['Kasir', 'transaction.create'],
    ['Manager', 'reports.sales'],
    ['Barista', 'kds.index'],
    ['Waiter', 'kds.index'],
    ['Inventory', 'products.index'],
    ['Owner', 'dashboard'],
]);
