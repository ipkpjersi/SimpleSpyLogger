<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Boot the app, then refuse to continue unless the default connection is
     * in-memory sqlite. This runs before setUpTraits(), so RefreshDatabase and
     * friends never get a chance to run migrate:fresh against a real database
     * (e.g. if config is cached or phpunit.xml env values get overridden).
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $connection = (string) $app['config']->get('database.default');
        $driver = (string) $app['config']->get("database.connections.{$connection}.driver");
        $database = (string) $app['config']->get("database.connections.{$connection}.database");

        if ($driver !== 'sqlite' || $database !== ':memory:') {
            throw new RuntimeException(
                "Refusing to run tests against database '{$database}' (driver '{$driver}'). "
                . 'Tests must use in-memory sqlite. If config is cached, run: php artisan config:clear'
            );
        }

        return $app;
    }
}
