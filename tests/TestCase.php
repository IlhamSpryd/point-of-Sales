<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\WithDefaultTenant;

abstract class TestCase extends BaseTestCase
{
    use WithDefaultTenant;

    protected function setUp(): void
    {
        parent::setUp();
        if (method_exists($this, 'setUpWithDefaultTenant')) {
            $this->setUpWithDefaultTenant();
        }
    }
}
