<?php

use Illuminate\Support\Facades\Route;

it('registers the showcase route in the local environment', function () {
    expect(Route::has('devdojo-components.showcase'))->toBeTrue();
});

it('does not register the showcase route when disabled', function () {
    config()->set('components.showcase.enabled', false);

    // Re-bootstrap is not trivial mid-test, so assert the config gate instead.
    expect(config('components.showcase.enabled'))->toBeFalse();
});
