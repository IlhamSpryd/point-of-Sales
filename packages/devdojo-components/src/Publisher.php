<?php

namespace DevDojo\Components;

use Illuminate\Filesystem\Filesystem;

class Publisher
{
    public function __construct(protected Filesystem $files) {}

    /**
     * The absolute path a component is published to in the host application.
     */
    public function destinationDir(string $name): string
    {
        return resource_path(trim((string) config('components.path', 'views/components'), '/')."/{$name}");
    }

    /**
     * Determine whether a component has already been published into the app.
     */
    public function isPublished(string $name): bool
    {
        return $this->files->isDirectory($this->destinationDir($name));
    }

    /**
     * The subset of the given names that are already published.
     *
     * @param  array<int, string>  $names
     * @return array<int, string>
     */
    public function publishedFrom(array $names): array
    {
        return array_values(array_filter($names, fn (string $name) => $this->isPublished($name)));
    }

    /**
     * Copy a component's source files into the host application, rewriting the
     * preview namespace to root anonymous components and skipping metadata.
     *
     * @return string One of 'missing', 'skipped', 'overwritten' or 'added'.
     */
    public function publish(string $name, bool $force = false): string
    {
        if (! Components::exists($name)) {
            return 'missing';
        }

        $destinationDir = $this->destinationDir($name);
        $alreadyPublished = $this->files->isDirectory($destinationDir);

        if ($alreadyPublished && ! $force) {
            return 'skipped';
        }

        foreach ($this->files->allFiles(Components::sourcePath($name)) as $file) {
            // Metadata is for the package's registry, not the host app.
            if ($file->getExtension() === 'json') {
                continue;
            }

            // Studio examples document the component; they are not part of it.
            if (str_starts_with($file->getRelativePathname(), 'examples/')) {
                continue;
            }

            $target = $destinationDir.'/'.$file->getRelativePathname();
            $this->files->ensureDirectoryExists(dirname($target));

            $contents = $this->files->get($file->getPathname());

            if (str_ends_with($file->getFilename(), '.blade.php')) {
                $contents = $this->transform($contents);
            }

            $this->files->put($target, $contents);
        }

        return $alreadyPublished ? 'overwritten' : 'added';
    }

    /**
     * Rewrite the preview namespace references to root anonymous components,
     * e.g. <x-components.button /> becomes <x-button /> and a dynamic
     * component="components.tiptap.icons.bold" becomes "tiptap.icons.bold".
     */
    public function transform(string $contents): string
    {
        $namespace = Components::NAMESPACE;
        $path = (string) config('components.path', 'views/components');
        $tagPrefix = str_ends_with(trim($path, '/'), '/ui') ? '<x-ui.' : '<x-';
        $tagClosePrefix = str_ends_with(trim($path, '/'), '/ui') ? '</x-ui.' : '</x-';

        return str_replace(
            ["<x-{$namespace}.", "</x-{$namespace}.", "component=\"{$namespace}.", "component='{$namespace}."],
            [$tagPrefix, $tagClosePrefix, 'component="', "component='"],
            $contents
        );
    }
}
