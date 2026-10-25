<?php

namespace DevDojo\Components\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class InstallCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'components:install {--force : Overwrite the theme file if it already exists}';

    /**
     * @var string
     */
    protected $description = 'Install the DevDojo components theme and wire it into your CSS';

    public function __construct(protected Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->newLine();
        $this->line('  <fg=magenta;options=bold>Installing DevDojo Components</>');
        $this->newLine();

        $this->publishTheme();
        $this->wireIntoAppCss();

        $this->newLine();
        $this->components->info('Installation complete.');
        $this->line('  Next: run <fg=green>php artisan components:add</> to add components, then <fg=green>npm run build</>.');
        $this->newLine();

        return self::SUCCESS;
    }

    protected function publishTheme(): void
    {
        $source = dirname(__DIR__, 2).'/resources/css/components.css';
        $destination = resource_path('css/components.css');

        if ($this->files->exists($destination) && ! $this->option('force')) {
            $this->components->twoColumnDetail('resources/css/components.css', '<fg=yellow>exists, skipped</>');

            return;
        }

        $this->files->ensureDirectoryExists(dirname($destination));
        $this->files->copy($source, $destination);

        $this->components->twoColumnDetail('resources/css/components.css', '<fg=green>published</>');
    }

    protected function wireIntoAppCss(): void
    {
        $appCss = resource_path('css/app.css');

        if (! $this->files->exists($appCss)) {
            $this->components->warn("Could not find resources/css/app.css — add @import './components.css'; manually.");

            return;
        }

        $contents = $this->files->get($appCss);

        if (str_contains($contents, 'components.css')) {
            $this->components->twoColumnDetail('resources/css/app.css', '<fg=gray>already imported</>');

            return;
        }

        $import = "@import './components.css';";

        // Place our import directly after the Tailwind import when present.
        if (preg_match('/^@import\s+["\']tailwindcss["\'];?\s*$/m', $contents)) {
            $contents = preg_replace(
                '/(^@import\s+["\']tailwindcss["\'];?\s*$)/m',
                "$1\n{$import}",
                $contents,
                1
            );
        } else {
            $contents = $import."\n".$contents;
        }

        $this->files->put($appCss, $contents);

        $this->components->twoColumnDetail('resources/css/app.css', '<fg=green>import added</>');
    }
}
