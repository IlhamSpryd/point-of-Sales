<?php

namespace DevDojo\Components\Console;

use DevDojo\Components\Components;
use DevDojo\Components\Publisher;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

use function Laravel\Prompts\multiselect;

class AddCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'components:add
        {component?* : One or more component names to add}
        {--all : Add every available component}
        {--force : Overwrite components that already exist}';

    /**
     * @var string
     */
    protected $description = 'Add DevDojo components to your application';

    public function __construct(protected Publisher $publisher)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $selected = $this->resolveSelection();

        if ($selected === null) {
            return self::FAILURE;
        }

        if ($selected === []) {
            $this->components->warn('No components selected. Nothing to add.');

            return self::SUCCESS;
        }

        $resolved = Components::withDependencies($selected);
        $pulledInDeps = array_values(array_diff($resolved, $selected));

        if ($pulledInDeps !== []) {
            $this->components->info('Including required dependencies: '.implode(', ', $pulledInDeps));
        }

        $added = 0;
        $skipped = 0;

        foreach ($resolved as $name) {
            $result = $this->addComponent($name);

            $result === 'skipped' ? $skipped++ : $added++;
        }

        $this->newLine();
        $this->components->info("Done. {$added} added".($skipped ? ", {$skipped} skipped" : '').'.');
        $this->outputUsageHint($resolved);

        return self::SUCCESS;
    }

    /**
     * Determine which components the user wants to add. Returns null when the
     * request is invalid (e.g. an unknown component was named).
     *
     * @return array<int, string>|null
     */
    protected function resolveSelection(): ?array
    {
        if ($this->option('all')) {
            return Components::names();
        }

        $arguments = array_values(array_filter((array) $this->argument('component')));

        if ($arguments !== []) {
            $unknown = array_diff($arguments, Components::names());

            if ($unknown !== []) {
                $this->components->error('Unknown component(s): '.implode(', ', $unknown));

                return null;
            }

            return $arguments;
        }

        return multiselect(
            label: 'Which components would you like to add?',
            options: collect(Components::all())
                ->mapWithKeys(fn ($meta, $name) => [$name => $meta['label'].' — '.Str::limit($meta['description'], 50)])
                ->all(),
            hint: 'Use the space bar to select, then press enter. Dependencies are added automatically.',
            scroll: 15,
        );
    }

    /**
     * Add a single component, returning 'added' or 'skipped'. The whole
     * component directory is copied (some components ship sub-views such as
     * tiptap's toolbar icons), with Blade files rewritten and metadata skipped.
     */
    protected function addComponent(string $name): string
    {
        $result = $this->publisher->publish($name, (bool) $this->option('force'));

        if ($result === 'skipped') {
            $this->components->twoColumnDetail(
                "<fg=yellow>$name</>",
                '<fg=yellow>exists, skipped (use --force)</>'
            );

            return 'skipped';
        }

        $this->components->twoColumnDetail(
            "<fg=green>$name</>",
            '<fg=gray>'.$this->relativePath($this->publisher->destinationDir($name)).'/</>'
        );

        return 'added';
    }

    protected function relativePath(string $path): string
    {
        return Str::after($path, base_path().DIRECTORY_SEPARATOR);
    }

    /**
     * @param  array<int, string>  $added
     */
    protected function outputUsageHint(array $added): void
    {
        $example = $added[0] ?? 'button';

        $this->newLine();
        $this->line("  Use them right away, e.g. <fg=cyan><x-{$example} /></>");

        if (in_array('toast', $added, true)) {
            $this->line('  <fg=gray>Tip: place <x-toast /> once in your layout to enable notifications.</>');
        }

        $needsAssets = collect($added)->contains(fn ($name) => Components::get($name)['assets'] ?? false);

        if ($needsAssets) {
            $this->line('  <fg=yellow>Some components need their JS bundle — run:</> <fg=green>php artisan vendor:publish --tag=devdojo-assets</>');
        }
    }
}
