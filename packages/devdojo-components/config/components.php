<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Preview Components
    |--------------------------------------------------------------------------
    |
    | Before you publish a component you can preview and use it under the
    | "components" namespace, e.g. <x-components.button />. Once published,
    | the component lives in your app and is used as <x-button />. Disable
    | this to stop the bundled components from being registered at all.
    |
    */

    'preview' => true,

    /*
    |--------------------------------------------------------------------------
    | Publish Path
    |--------------------------------------------------------------------------
    |
    | The directory (relative to your resource path) where components are
    | copied when you run `php artisan components:add`. Each component
    | is written to its own folder as an anonymous index component, e.g.
    | resources/views/components/button/index.blade.php → <x-button />.
    |
    */

    'path' => 'views/components',

    /*
    |--------------------------------------------------------------------------
    | Showcase
    |--------------------------------------------------------------------------
    |
    | A built-in gallery that renders every component so you can see exactly
    | what you are getting before you publish. It is only ever registered in
    | the "local" environment and can be toggled off entirely here.
    |
    */

    'showcase' => [
        'enabled' => env('COMPONENTS_SHOWCASE', true),
        'route' => '/components',

        /*
         | The middleware applied to the showcase route. It must include the
         | session + CSRF stack (the "web" group) so the Livewire "add" buttons
         | can post back without triggering a 419 "page expired" response.
         */
        'middleware' => ['web'],

        /*
         | The Vite entry points loaded by the studio pages and the preview
         | iframe. Point this at whatever stylesheet compiles your Tailwind
         | theme (it must include the DevDojo Components tokens).
         */
        'assets' => ['resources/css/app.css'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Public Preview Route
    |--------------------------------------------------------------------------
    |
    | The studio above is a local-only tool, but its preview endpoint — an
    | isolated, injection-neutralized render of a single component — is safe
    | to serve anywhere. Enable this to register GET {showcase.route}/
    | {name}/preview in every environment (e.g. for a public component
    | gallery). The throttle guards a public deployment.
    |
    */

    'preview_route' => [
        'enabled' => env('COMPONENTS_PREVIEW_ROUTE', false),
        'middleware' => ['web', 'throttle:120,1'],
    ],

];
