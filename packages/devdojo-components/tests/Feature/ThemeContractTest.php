<?php

use DevDojo\Components\Theme\ThemeContract;

it('publishes a stable portable theme contract', function () {
    expect(ThemeContract::REFERENCE)->toBe('devdojo.theme@1')
        ->and(ThemeContract::descriptor())->toBe(['id' => 'devdojo.theme', 'version' => 1])
        ->and(ThemeContract::supports(ThemeContract::descriptor()))->toBeTrue()
        ->and(ThemeContract::supports(['id' => 'devdojo.theme', 'version' => 2]))->toBeFalse();
});

it('keeps every contract token wired into the published theme stylesheet', function () {
    $css = file_get_contents(__DIR__.'/../../resources/css/components.css');

    foreach (ThemeContract::COLOR_TOKENS as $token) {
        expect(substr_count($css, "--{$token}:"))->toBeGreaterThanOrEqual(2, $token)
            ->and($css)->toContain("--color-{$token}: var(--{$token});");
    }

    foreach (ThemeContract::RADIUS_TOKENS as $token) {
        expect($css)->toContain("--{$token}:")
            ->and($css)->toContain("--{$token}: var(--{$token});");
    }
});
