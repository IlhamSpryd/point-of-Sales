<?php

namespace DevDojo\Components;

use DevDojo\Components\Console\AddCommand;
use DevDojo\Components\Console\InstallCommand;
use DevDojo\Components\Console\ListCommand;
use DevDojo\Components\Http\StudioController;
use DevDojo\Components\Livewire\ComponentCard;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\ComponentAttributeBag;
use Livewire\Livewire;
use TailwindMerge\TailwindMerge;

class ComponentsServiceProvider extends ServiceProvider
{
    /**
     * Register any package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/components.php', 'components');
    }

    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        $this->registerTailwindMergeFallbacks();
        $this->registerPreviewComponents();
        $this->registerLivewireComponents();
        $this->registerPreviewRoute();
        $this->registerStudio();
        $this->registerPublishing();

        if ($this->app->runningInConsole()) {
            $this->commands([
                ListCommand::class,
                AddCommand::class,
                InstallCommand::class,
            ]);
        }
    }

    /**
     * Register the bundled components under the "components" preview namespace
     * so they can be used (e.g. <x-components.button />) before publishing.
     *
     * The components live in resources/components/{name}/index.blade.php, so we
     * register the parent directory and let the "components.*" dot notation map
     * straight onto that folder — the same convention Laravel uses everywhere.
     */
    protected function registerPreviewComponents(): void
    {
        if (! config('components.preview', true)) {
            return;
        }

        Blade::anonymousComponentPath(dirname(Components::sourcePath()), Components::NAMESPACE);
    }

    /**
     * Register the Livewire components used by the showcase gallery — only when
     * Livewire is installed, so the core package stays Livewire-free.
     */
    protected function registerLivewireComponents(): void
    {
        if (! class_exists(Livewire::class)) {
            return;
        }

        Livewire::component('devdojo-components.card', ComponentCard::class);
    }

    /**
     * Register the standalone preview endpoint. It renders one component as an
     * isolated document and is safe for production (input is neutralized, output
     * cacheable), so it registers whenever the studio is active OR the
     * preview_route flag is on — but only once.
     */
    protected function registerPreviewRoute(): void
    {
        if (! $this->studioActive() && ! config('components.preview_route.enabled', false)) {
            return;
        }

        Route::middleware(config('components.preview_route.middleware', ['web', 'throttle:120,1']))
            ->prefix(config('components.showcase.route', '/components'))
            ->group(function (): void {
                Route::get('/{name}/preview', [StudioController::class, 'preview'])
                    ->where('name', '[a-z0-9\-]+')
                    ->name('devdojo-components.preview');
            });
    }

    /**
     * Whether the local-only Component Studio should register.
     */
    protected function studioActive(): bool
    {
        return config('components.showcase.enabled', false)
            && $this->app->environment(['local', 'testing']);
    }

    /**
     * Register the local-only Component Studio routes and views.
     */
    protected function registerStudio(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'devdojo-components');

        if (! $this->studioActive()) {
            return;
        }

        // The studio needs the session + CSRF stack (the "web" group) so the
        // Livewire "add" buttons can post back without a 419 "page expired".
        Route::middleware(config('components.showcase.middleware', ['web']))
            ->prefix(config('components.showcase.route', '/components'))
            ->group(function (): void {
                Route::get('/', [StudioController::class, 'index'])
                    ->name('devdojo-components.showcase');
                Route::get('/llms.txt', [StudioController::class, 'llms'])
                    ->name('devdojo-components.llms');
                Route::get('/guide/{page}', [StudioController::class, 'guide'])
                    ->where('page', '[a-z0-9\-]+')
                    ->name('devdojo-components.guide');
                Route::get('/{name}', [StudioController::class, 'show'])
                    ->where('name', '[a-z0-9\-]+')
                    ->name('devdojo-components.show');
            });
    }

    /**
     * Register the publishable config and CSS theme files.
     */
    protected function registerPublishing(): void
    {
        $this->publishes([
            __DIR__.'/../config/components.php' => config_path('components.php'),
        ], 'components-config');

        $this->publishes([
            __DIR__.'/../resources/css/components.css' => resource_path('css/components.css'),
        ], 'components-theme');

        // Compiled JS for the asset-backed components (Monaco, Tiptap).
        $this->publishes([
            __DIR__.'/../public/devdojo' => public_path('devdojo'),
        ], 'devdojo-assets');
    }

    /**
     * Provide graceful fallbacks for the TailwindMerge attribute macros so the
     * published components keep working even if the merge package is removed.
     * When gehrisandro/tailwind-merge-laravel is installed it registers these
     * first and the fallbacks below are skipped.
     */
    protected function registerTailwindMergeFallbacks(): void
    {
        if (! ComponentAttributeBag::hasMacro('twMerge')) {
            ComponentAttributeBag::macro('twMerge', function (string $classes = '') {
                /** @var ComponentAttributeBag $this */
                $existing = (string) $this->get('class', '');
                if (class_exists(TailwindMerge::class)) {
                    $merged = TailwindMerge::instance()->merge($classes, $existing);

                    return $this->merge(['class' => $merged]);
                }

                return $this->merge(['class' => $classes]);
            });
        }

        if (! ComponentAttributeBag::hasMacro('twMergeFor')) {
            ComponentAttributeBag::macro('twMergeFor', function (string $key, string $classes = '') {
                /** @var ComponentAttributeBag $this */
                $scoped = (string) ($this->get($key.':class') ?? '');
                if (class_exists(TailwindMerge::class)) {
                    $merged = TailwindMerge::instance()->merge($classes, $scoped);

                    return new ComponentAttributeBag([
                        'class' => $merged,
                    ]);
                }

                return new ComponentAttributeBag([
                    'class' => trim($classes.' '.$scoped),
                ]);
            });
        }

        if (! ComponentAttributeBag::hasMacro('withoutTwMergeClasses')) {
            ComponentAttributeBag::macro('withoutTwMergeClasses', function () {
                /** @var ComponentAttributeBag $this */
                return $this->filter(function ($value, $key) {
                    return $key !== 'class' && ! str_ends_with((string) $key, ':class');
                });
            });
        }
    }
}
