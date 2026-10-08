<?php

/**
 * Tes integritas sidebar: memastikan config/navigation.php selalu sinkron
 * dengan routes/web.php. Dua test ini, kalau sudah ada dari awal, akan
 * menangkap bug Inventory (§4 baris 8-9) dan export-tasks (§3.1)
 * sebelum merge.
 *
 * @see config/navigation.php
 * @see §8.5 audit navigasi
 */

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

uses(TestCase::class);

it('setiap route yang dirujuk sidebar benar-benar terdaftar', function () {
    $allRoutes = collect(config('navigation.sections'))->flatten(1)->pluck('route');

    $missing = $allRoutes->reject(fn ($name) => Route::has($name));

    expect($missing->values()->all())->toBeEmpty(
        "Sidebar mereferensikan route yang tidak terdaftar: {$missing->implode(', ')}."
    );
});

it('daftar role di sidebar SAMA PERSIS dengan middleware role: pada route tujuannya', function () {
    foreach (collect(config('navigation.sections'))->flatten(1) as $item) {
        if (! Route::has($item['route'])) {
            continue; // sudah ditangkap test di atas
        }

        $route = Route::getRoutes()->getByName($item['route']);
        $roleMiddleware = collect($route->gatherMiddleware())
            ->first(fn ($m) => str_starts_with($m, 'role:'));

        if (! $roleMiddleware) {
            continue; // route ini memang tidak dijaga per-role
        }

        $routeRoles = collect(explode(',', substr($roleMiddleware, strlen('role:'))))->sort()->values()->all();
        $menuRoles = collect($item['roles'])->sort()->values()->all();

        expect($menuRoles)->toBe($routeRoles,
            "Menu '{$item['label']}' (route: {$item['route']}) menampilkan roles=[".implode(',', $menuRoles).'] '.
            'tapi middleware hanya mengizinkan roles=['.implode(',', $routeRoles).']. '.
            'Kalau lebih sempit → fitur tersembunyi dari role yang sebenarnya berhak. '.
            'Kalau lebih luas → role akan 403 setelah klik menu.'
        );
    }
});
