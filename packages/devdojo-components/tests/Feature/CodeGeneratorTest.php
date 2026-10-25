<?php

use DevDojo\Components\CodeGenerator;

it('generates a self-closing tag with no attrs or slots', function () {
    expect(CodeGenerator::generate('button'))->toBe('<x-components.button />');
});

it('generates published syntax when requested', function () {
    expect(CodeGenerator::generate('button', published: true))->toBe('<x-button />');
});

it('renders attributes one per line and skips empty, null and false values', function () {
    $code = CodeGenerator::generate('button', [
        'variant' => 'ghost',
        'href' => '',
        'loader' => false,
        'loading' => 'false',
        'size' => null,
    ], published: true);

    expect($code)->toBe("<x-button\n    variant=\"ghost\"\n/>");
});

it('renders true values as bare attributes', function () {
    $code = CodeGenerator::generate('button', ['loader' => true, 'loading' => 'true'], published: true);

    expect($code)->toBe("<x-button\n    loader\n    loading\n/>");
});

it('renders arrays and JSON strings as PHP literal bindings', function () {
    $code = CodeGenerator::generate('breadcrumbs', ['items' => ['Home', 'Library']], published: true);
    expect($code)->toContain(":items=\"['Home', 'Library']\"");

    $code = CodeGenerator::generate('breadcrumbs', ['items' => '["Home", "Library"]'], published: true);
    expect($code)->toContain(":items=\"['Home', 'Library']\"");
});

it('renders associative arrays as PHP literal maps', function () {
    $code = CodeGenerator::generate('select', ['options' => ['us' => 'United States']], published: true);

    expect($code)->toContain(":options=\"['us' => 'United States']\"");
});

it('drops array values whose PHP literal would contain a double quote', function () {
    $code = CodeGenerator::generate('button', ['variant' => ['x" <x-evil z=']], published: true);

    expect($code)->toBe('<x-button />');

    $code = CodeGenerator::generate('button', ['variant' => '{"a":"x\" <x-evil>"}'], published: true);

    expect($code)->toBe('<x-button />');
});

it('escapes double quotes in string values', function () {
    $code = CodeGenerator::generate('input', ['placeholder' => 'Say "hi"'], published: true);

    expect($code)->toContain('placeholder="Say &quot;hi&quot;"');
});

it('renders a default slot on its own indented line', function () {
    $code = CodeGenerator::generate('button', ['variant' => 'ghost'], ['slot' => 'Save'], published: true);

    expect($code)->toBe("<x-button\n    variant=\"ghost\"\n>\n    Save\n</x-button>");
});

it('renders a slot without attributes on one tag pair', function () {
    $code = CodeGenerator::generate('button', [], ['slot' => 'Save'], published: true);

    expect($code)->toBe("<x-button>\n    Save\n</x-button>");
});

it('renders named slots and skips empty ones', function () {
    $code = CodeGenerator::generate('card', [], [
        'slot' => 'Body',
        'footer' => '<x-button size="sm">Go</x-button>',
        'header' => '',
    ], published: true);

    expect($code)->toBe("<x-card>\n    Body\n    <x-slot:footer><x-button size=\"sm\">Go</x-button></x-slot:footer>\n</x-card>");
});
