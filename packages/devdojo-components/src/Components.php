<?php

namespace DevDojo\Components;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class Components
{
    /**
     * The namespace the bundled components are previewed under, e.g.
     * <x-components.button />. This matches the source folder name and is
     * stripped when components are published into the host application.
     */
    public const NAMESPACE = 'components';

    /**
     * In-memory cache of the parsed component manifest.
     *
     * @var array<string, array<string, mixed>>|null
     */
    protected static ?array $manifest = null;

    /**
     * The absolute path to the package's source component directory.
     */
    public static function sourcePath(string $path = ''): string
    {
        $base = dirname(__DIR__).'/resources/components';

        return $path === '' ? $base : $base.'/'.ltrim($path, '/');
    }

    /**
     * Every available component keyed by its name.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        if (static::$manifest !== null) {
            return static::$manifest;
        }

        $manifest = [];

        foreach (glob(static::sourcePath('*'), GLOB_ONLYDIR) ?: [] as $directory) {
            $name = basename($directory);
            $metaFile = $directory.'/'.$name.'.json';
            $bladeFile = $directory.'/index.blade.php';

            if (! is_file($bladeFile)) {
                continue;
            }

            $meta = is_file($metaFile)
                ? (json_decode((string) file_get_contents($metaFile), true) ?: [])
                : [];

            $manifest[$name] = array_merge([
                'name' => $name,
                'label' => Str::headline($name),
                'description' => '',
                'category' => 'Components',
                'dependencies' => [],
                'props' => [],
                'slots' => [],
                'examples' => [],
                'studio' => [],
            ], $meta);
        }

        ksort($manifest);

        return static::$manifest = $manifest;
    }

    /**
     * Determine whether a component exists.
     */
    public static function exists(string $name): bool
    {
        return array_key_exists($name, static::all());
    }

    /**
     * Get the metadata for a single component.
     *
     * @return array<string, mixed>|null
     */
    public static function get(string $name): ?array
    {
        return static::all()[$name] ?? null;
    }

    /**
     * The list of available component names.
     *
     * @return array<int, string>
     */
    public static function names(): array
    {
        return array_keys(static::all());
    }

    /**
     * The source of a component's declared example file, or null when the
     * component or example is unknown. Only filenames declared in the
     * component's manifest resolve — never arbitrary paths.
     */
    public static function exampleSource(string $name, string $file): ?string
    {
        $component = static::get($name);

        if ($component === null) {
            return null;
        }

        // Reject path traversal attempts — only simple filenames allowed
        if (str_contains($file, '/') || str_contains($file, '\\')) {
            return null;
        }

        $requested = Str::of($file)->basename('.blade.php')->value();

        foreach ($component['examples'] as $example) {
            if (Str::of($example['file'])->basename('.blade.php')->value() !== $requested) {
                continue;
            }

            $path = static::sourcePath($component['name'].'/examples/'.$example['file']);

            return is_file($path) ? (string) file_get_contents($path) : null;
        }

        return null;
    }

    /**
     * Components grouped by their category, sorted for display.
     *
     * @return Collection<string, Collection<int, array<string, mixed>>>
     */
    public static function byCategory(): Collection
    {
        $order = ['Forms', 'Layout', 'Navigation', 'Display', 'Overlays', 'Feedback', 'Editors'];

        return collect(static::all())
            ->groupBy('category')
            ->sortBy(fn ($components, $category) => array_search($category, $order) === false
                ? PHP_INT_MAX
                : array_search($category, $order));
    }

    /**
     * Expand a set of component names to include every dependency they need.
     *
     * @param  array<int, string>  $names
     * @return array<int, string>
     */
    public static function withDependencies(array $names): array
    {
        $resolved = [];

        $resolver = function (string $name) use (&$resolver, &$resolved): void {
            if (in_array($name, $resolved, true) || ! static::exists($name)) {
                return;
            }

            foreach (static::get($name)['dependencies'] ?? [] as $dependency) {
                $resolver($dependency);
            }

            $resolved[] = $name;
        };

        foreach ($names as $name) {
            $resolver($name);
        }

        return $resolved;
    }
}
