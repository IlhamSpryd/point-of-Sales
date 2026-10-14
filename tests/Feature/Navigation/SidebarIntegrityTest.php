<?php

declare(strict_types=1);

namespace Tests\Feature\Navigation;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SidebarIntegrityTest extends TestCase
{
    public function test_all_routes_referenced_by_sidebar_are_registered(): void
    {
        $allRoutes = collect(config('navigation.sections'))->flatten(1)->pluck('route');

        $missing = $allRoutes->reject(fn ($name) => Route::has($name));

        $this->assertEmpty(
            $missing->values()->all(),
            "Sidebar mereferensikan route yang tidak terdaftar: {$missing->implode(', ')}."
        );
    }

    public function test_role_list_in_sidebar_matches_role_middleware_on_target_route(): void
    {
        foreach (collect(config('navigation.sections'))->flatten(1) as $item) {
            if (! Route::has($item['route'])) {
                continue;
            }

            $route = Route::getRoutes()->getByName($item['route']);
            $roleMiddleware = collect($route->gatherMiddleware())
                ->first(fn ($m) => str_starts_with($m, 'role:'));

            if (! $roleMiddleware) {
                continue;
            }

            $routeRoles = collect(explode(',', substr($roleMiddleware, strlen('role:'))))->sort()->values()->all();
            $menuRoles = collect($item['roles'])->sort()->values()->all();

            $this->assertEquals(
                $routeRoles,
                $menuRoles,
                "Menu '{$item['label']}' (route: {$item['route']}) roles mismatch with route middleware."
            );
        }
    }
}
