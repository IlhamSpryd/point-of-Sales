<?php

use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->withoutVite();
});

it('registers the preview route exactly once alongside the studio', function () {
    $registered = collect(Route::getRoutes()->getRoutesByName())
        ->keys()
        ->filter(fn ($name) => $name === 'devdojo-components.preview');

    expect(Route::has('devdojo-components.preview'))->toBeTrue()
        ->and(Route::has('devdojo-components.showcase'))->toBeTrue()
        ->and($registered)->toHaveCount(1);
});

it('applies the preview_route middleware to the preview route', function () {
    $route = Route::getRoutes()->getByName('devdojo-components.preview');

    expect($route->middleware())->toContain('throttle:120,1');
});
