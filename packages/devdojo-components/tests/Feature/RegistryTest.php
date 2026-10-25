<?php

use DevDojo\Components\Components;

it('discovers every bundled component', function () {
    $names = Components::names();

    expect($names)->toContain(
        'button', 'input', 'label', 'card', 'checkbox', 'radio', 'toggle',
        'modal', 'dropdown', 'popover', 'tooltip', 'alert', 'toast',
        'drawer', 'monaco-editor', 'tiptap',
    );
});

it('loads metadata for each component', function () {
    foreach (Components::all() as $name => $meta) {
        expect($meta)->toHaveKeys(['name', 'label', 'description', 'category'])
            ->and($meta['name'])->toBe($name)
            ->and($meta['description'])->not->toBe('')
            ->and(is_file(Components::sourcePath($name.'/index.blade.php')))->toBeTrue();
    }
});

it('resolves dependencies before the component that needs them', function () {
    $resolved = Components::withDependencies(['input', 'modal']);

    // label is required by input, button is required by modal
    expect($resolved)->toContain('label', 'input', 'button', 'modal')
        ->and(array_search('label', $resolved))->toBeLessThan(array_search('input', $resolved))
        ->and(array_search('button', $resolved))->toBeLessThan(array_search('modal', $resolved));
});

it('groups components by category in display order', function () {
    expect(Components::byCategory()->keys()->all())
        ->toBe(['Forms', 'Layout', 'Navigation', 'Display', 'Overlays', 'Feedback', 'Editors']);
});

it('defaults slots, examples and studio keys on every manifest entry', function () {
    foreach (Components::all() as $component) {
        expect($component['slots'])->toBeArray()
            ->and($component['examples'])->toBeArray()
            ->and($component['studio'])->toBeArray();
    }
});

it('declares complete, well-formed studio metadata', function () {
    $allowedTypes = ['text', 'select', 'boolean', 'textarea', 'number', 'integer', 'array'];

    foreach (Components::all() as $component) {
        foreach ($component['props'] as $prop) {
            expect($prop)->toHaveKey('name');
            expect($prop['type'] ?? 'text')->toBeIn($allowedTypes);
        }

        foreach ($component['slots'] as $slot) {
            expect($slot)->toHaveKey('name');
        }

        if ($container = $component['studio']['container'] ?? null) {
            expect($container)->toContain('{{component}}');
        }
    }
});

it('declares slots on every component that renders a default slot', function () {
    foreach (Components::all() as $component) {
        $blade = file_get_contents(Components::sourcePath($component['name'].'/index.blade.php'));

        if (str_contains($blade, '{{ $slot }}')) {
            expect(collect($component['slots'])->pluck('name'))->toContain('slot');
        }
    }
});

it('returns a declared example source', function () {
    $source = Components::exampleSource('button', 'variants');

    expect($source)->toContain('<x-components.button');
});

it('accepts the full example filename', function () {
    expect(Components::exampleSource('button', 'variants.blade.php'))
        ->toBe(Components::exampleSource('button', 'variants'));
});

it('rejects undeclared example files', function () {
    expect(Components::exampleSource('button', 'index'))->toBeNull()
        ->and(Components::exampleSource('button', '../button.json'))->toBeNull()
        ->and(Components::exampleSource('button', '../../alert/examples/variants'))->toBeNull();
});

it('returns null for unknown components', function () {
    expect(Components::exampleSource('nope', 'variants'))->toBeNull();
});
