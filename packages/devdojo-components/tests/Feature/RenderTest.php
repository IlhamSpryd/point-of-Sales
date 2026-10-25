<?php

use DevDojo\Components\Components;
use Illuminate\Support\Facades\Blade;

it('renders every component under the preview namespace', function (string $name) {
    $html = Blade::render("<x-components.{$name} />");

    expect($html)->toBeString();
})->with(Components::names());

it('renders a button with merged classes and slot content', function () {
    $html = Blade::render('<x-components.button class="w-full">Save</x-components.button>');

    expect($html)->toContain('Save')
        ->toContain('w-full')
        ->toContain('<button');
});

it('renders the destructive button variant', function () {
    $html = Blade::render('<x-components.button variant="destructive">Delete</x-components.button>');

    expect($html)->toContain('bg-destructive');
});

it('renders an input with its label and resolves nested components', function () {
    $html = Blade::render('<x-components.input label="Email" type="email" />');

    expect($html)->toContain('Email')
        ->toContain('type="email"');
});
