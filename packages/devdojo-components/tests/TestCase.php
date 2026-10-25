<?php

namespace DevDojo\Components\Tests;

use DevDojo\Components\ComponentsServiceProvider;
use Illuminate\Foundation\Application;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use TailwindMerge\Laravel\TailwindMergeServiceProvider;

class TestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            TailwindMergeServiceProvider::class,
            ComponentsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['env'] = 'local';
        $app['config']->set('app.env', 'local');
        // Livewire signs its snapshots with the app key when components render.
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    }
}
