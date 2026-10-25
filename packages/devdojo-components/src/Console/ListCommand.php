<?php

namespace DevDojo\Components\Console;

use DevDojo\Components\Components;
use Illuminate\Console\Command;

class ListCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'components:list';

    /**
     * @var string
     */
    protected $description = 'List every available DevDojo component';

    public function handle(): int
    {
        $this->newLine();
        $this->line('  <fg=magenta;options=bold>DevDojo Components</>');
        $this->line('  <fg=gray>'.count(Components::names()).' components ready to add</>');

        foreach (Components::byCategory() as $category => $components) {
            $this->newLine();
            $this->line("  <fg=cyan;options=bold>{$category}</>");

            foreach ($components as $component) {
                $this->components->twoColumnDetail(
                    "  <fg=white>{$component['name']}</>",
                    "<fg=gray>{$component['description']}</>"
                );
            }
        }

        $this->newLine();
        $this->line('  Add with <fg=green>php artisan components:add</> or <fg=green>php artisan components:add button alert</>');
        $this->newLine();

        return self::SUCCESS;
    }
}
