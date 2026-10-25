<?php

namespace DevDojo\Components;

/**
 * Renders component documentation as Markdown — used by the studio's
 * "Copy Markdown" button and the /llms.txt endpoint so AI assistants can
 * ingest the whole library in one paste-ready document.
 */
class Markdown
{
    /**
     * A single component's docs: description, install command, every example
     * with its published code, and the props/slots reference tables.
     *
     * @param  array<string, mixed>  $component
     * @param  list<array<string, mixed>>|null  $examples  Examples carrying a 'code' key; loaded from disk when omitted.
     */
    public static function component(array $component, ?array $examples = null): string
    {
        $examples ??= static::examplesWithCode($component);

        $lines = [
            '# '.$component['label'].' — DevDojo Components',
            '',
            $component['description'],
            '',
            'Install: `php artisan components:add '.$component['name'].'` — the Blade source is published into `resources/views/components/'.$component['name'].'` and used as `<x-'.$component['name'].'>`.',
        ];

        foreach ($examples as $example) {
            $lines[] = '';
            $lines[] = '## '.$example['title'];
            $lines[] = '';

            if (($example['description'] ?? '') !== '') {
                $lines[] = $example['description'];
                $lines[] = '';
            }

            $lines[] = '```blade';
            $lines[] = $example['code'];
            $lines[] = '```';
        }

        if (count($component['props'])) {
            $lines[] = '';
            $lines[] = '## Props';
            $lines[] = '';
            $lines[] = '| Prop | Type | Default | Description |';
            $lines[] = '| --- | --- | --- | --- |';

            foreach ($component['props'] as $prop) {
                $lines[] = '| `'.$prop['name'].'` | '.static::propType($prop).' | '.static::literal($prop['default'] ?? '').' | '.($prop['description'] ?? '').' |';
            }
        }

        if (count($component['slots'])) {
            $lines[] = '';
            $lines[] = '## Slots';
            $lines[] = '';
            $lines[] = '| Slot | Description |';
            $lines[] = '| --- | --- |';

            foreach ($component['slots'] as $slot) {
                $lines[] = '| `'.$slot['name'].'` | '.($slot['description'] ?? '').' |';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * The entire library as one Markdown document (the /llms.txt payload).
     */
    public static function full(): string
    {
        $lines = [
            '# DevDojo Components',
            '',
            'Beautiful, accessible Laravel Blade + Alpine components, published straight into your app with `php artisan components:add {name}` — from then on you own and edit the source. Styling is Tailwind CSS v4 on semantic theme tokens with automatic dark mode; consumers override styles by passing classes, which merge intelligently over the defaults.',
            '',
            'After publishing, a component is used as a normal Blade tag: `<x-button>`, `<x-input wire:model="email" />`, and so on. Component dependencies are resolved automatically when adding.',
            '',
            'Reference docs for every component follow, separated by horizontal rules.',
        ];

        foreach (Components::byCategory() as $components) {
            foreach ($components as $component) {
                $lines[] = '';
                $lines[] = '---';
                $lines[] = '';
                $lines[] = static::component(Components::get($component['name']));
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Load a component's examples and attach their published code.
     *
     * @param  array<string, mixed>  $component
     * @return list<array<string, mixed>>
     */
    protected static function examplesWithCode(array $component): array
    {
        return collect($component['examples'])->map(function (array $example) use ($component) {
            $file = Components::sourcePath($component['name'].'/examples/'.$example['file']);
            $source = is_file($file) ? (string) file_get_contents($file) : '';

            return $example + ['code' => trim(app(Publisher::class)->transform($source))];
        })->values()->all();
    }

    /**
     * @param  array<string, mixed>  $prop
     */
    protected static function propType(array $prop): string
    {
        $type = $prop['type'] ?? 'text';

        if (empty($prop['options'])) {
            return $type;
        }

        return $type.': '.implode(' \| ', array_map(fn ($option) => static::literal($option), $prop['options']));
    }

    /**
     * A prop default/option rendered for a Markdown table cell.
     */
    protected static function literal(mixed $value): string
    {
        return match (true) {
            is_bool($value) => var_export($value, true),
            is_array($value) => json_encode($value),
            $value === '' || $value === null => '—',
            default => (string) $value,
        };
    }
}
