<?php

use DevDojo\Components\Tests\ProductionTestCase;
use Illuminate\Support\Facades\Route;

uses(ProductionTestCase::class);

it('does not register any studio route outside the local environment', function () {
    expect(Route::has('devdojo-components.showcase'))->toBeFalse()
        ->and(Route::has('devdojo-components.show'))->toBeFalse()
        ->and(Route::has('devdojo-components.preview'))->toBeFalse()
        ->and(Route::has('devdojo-components.guide'))->toBeFalse();
});
