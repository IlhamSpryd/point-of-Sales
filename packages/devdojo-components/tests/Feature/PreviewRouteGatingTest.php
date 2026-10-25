<?php

use DevDojo\Components\Tests\ProductionTestCase;
use Illuminate\Support\Facades\Route;

uses(ProductionTestCase::class);

it('does not register the preview route by default outside local/testing', function () {
    expect(Route::has('devdojo-components.preview'))->toBeFalse()
        ->and(Route::has('devdojo-components.showcase'))->toBeFalse();
});
