<?php

use DevDojo\Components\Tests\ProductionWithPreviewRouteTestCase;
use Illuminate\Support\Facades\Route;

// The "public component gallery" deployment shape: production, with the
// standalone preview route opted into via components.preview_route.enabled.
uses(ProductionWithPreviewRouteTestCase::class);

beforeEach(function () {
    $this->withoutVite();
});

it('registers only the preview route when preview_route is enabled', function () {
    expect(Route::has('devdojo-components.preview'))->toBeTrue()
        ->and(Route::has('devdojo-components.showcase'))->toBeFalse()
        ->and(Route::has('devdojo-components.show'))->toBeFalse();
});

it('serves a cacheable preview when enabled', function () {
    $this->get('/components/button/preview')
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=300, public');
});
