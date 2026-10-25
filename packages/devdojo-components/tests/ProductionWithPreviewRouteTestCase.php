<?php

namespace DevDojo\Components\Tests;

/**
 * Production environment with the standalone preview route flag turned on —
 * the "public component gallery" deployment shape. Kept as its own trait
 * (rather than composed from ProductionTestCase) because Pest mixes traits
 * used together into one dynamic class, and two traits both declaring
 * defineEnvironment() would collide.
 */
trait ProductionWithPreviewRouteTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['env'] = 'production';
        $app['config']->set('app.env', 'production');
        $app['config']->set('components.preview_route.enabled', true);
    }
}
