<?php

use DevDojo\Components\Components;
use Illuminate\Support\Facades\Blade;

it('declares at least one example per component', function (string $name) {
    expect(Components::get($name)['examples'])->not->toBeEmpty();
})->with(fn () => array_map(fn ($n) => [$n], Components::names()));

it('has a real file behind every declared example that renders', function () {
    foreach (Components::all() as $component) {
        foreach ($component['examples'] as $example) {
            $file = Components::sourcePath($component['name'].'/examples/'.$example['file']);

            expect(is_file($file))->toBeTrue($component['name'].' example missing: '.$example['file']);
            expect(Blade::render((string) file_get_contents($file)))->toBeString();
        }
    }
});

it('shows example titles on the docs tab', function () {
    foreach (['alert', 'card', 'input'] as $name) {
        $response = $this->withoutVite()->get('/components/'.$name)->assertOk();

        foreach (Components::get($name)['examples'] as $example) {
            $response->assertSee($example['title']);
        }
    }
});
