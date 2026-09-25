<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('mengarahkan semua role ke route home setelah login', function (string $roleName) {
    $role = Role::firstOrCreate(['name' => $roleName]);
    $user = User::factory()->create(['role_id' => $role->id]);

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('home', absolute: false));
})->with([
    'Kasir',
    'Manager',
    'Barista',
    'Waiter',
    'Inventory',
    'Owner',
    'Supervisor',
    'Cook',
]);

it('HomeController mengarahkan setiap role ke halaman yang benar', function (string $roleName, string $expectedRoute) {
    $role = Role::firstOrCreate(['name' => $roleName]);
    $user = User::factory()->create(['role_id' => $role->id]);

    $this->actingAs($user)
        ->get(route('home'))
        ->assertRedirect(route($expectedRoute, absolute: false));
})->with([
    ['Kasir', 'transaction.create'],
    ['Manager', 'reports.sales'],
    ['Barista', 'kds.index'],
    ['Waiter', 'kds.index'],
    ['Cook', 'kds.index'],
    ['Inventory', 'inventory.ingredients'],
    ['Supervisor', 'shifts.index'],
    ['Owner', 'dashboard'],
]);

it('role tanpa pemetaan melihat halaman no-access, bukan 403', function () {
    $role = Role::firstOrCreate(['name' => 'CustomTestRole']);
    $user = User::factory()->create(['role_id' => $role->id]);

    $this->actingAs($user)
        ->get(route('home'))
        ->assertOk()
        ->assertViewIs('home.no-access');
});
