<?php

use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->target = resource_path('views/components');
    File::deleteDirectory($this->target);
});

afterEach(function () {
    File::deleteDirectory($this->target);
});

it('adds a component as an anonymous index component', function () {
    $this->artisan('components:add', ['component' => ['button']])
        ->assertSuccessful();

    $path = resource_path('views/components/button/index.blade.php');

    expect(File::exists($path))->toBeTrue()
        ->and(File::get($path))->toContain('@props');
});

it('pulls in dependencies automatically', function () {
    $this->artisan('components:add', ['component' => ['input']])
        ->assertSuccessful();

    expect(File::exists(resource_path('views/components/input/index.blade.php')))->toBeTrue()
        ->and(File::exists(resource_path('views/components/label/index.blade.php')))->toBeTrue();
});

it('rewrites the preview namespace to root components when added', function () {
    $this->artisan('components:add', ['component' => ['input']])->assertSuccessful();

    $contents = File::get(resource_path('views/components/input/index.blade.php'));

    expect($contents)->toContain('<x-label')
        ->not->toContain('x-components.');
});

it('skips existing components unless forced', function () {
    $path = resource_path('views/components/button/index.blade.php');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, 'CUSTOM');

    $this->artisan('components:add', ['component' => ['button']])->assertSuccessful();
    expect(File::get($path))->toBe('CUSTOM');

    $this->artisan('components:add', ['component' => ['button'], '--force' => true])->assertSuccessful();
    expect(File::get($path))->not->toBe('CUSTOM');
});

it('rejects unknown components', function () {
    $this->artisan('components:add', ['component' => ['nope']])
        ->assertFailed();
});
