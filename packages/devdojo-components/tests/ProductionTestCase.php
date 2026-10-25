<?php

namespace DevDojo\Components\Tests;

trait ProductionTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['env'] = 'production';
        $app['config']->set('app.env', 'production');
    }
}
