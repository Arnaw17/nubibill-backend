<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
        $app['config']->set('session.driver', 'array');

        \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\ConnectionEstablished::class, function ($event) {
            if ($event->connection->getDriverName() === 'sqlite') {
                $event->connection->getPdo()->sqliteCreateFunction('JSON_UNQUOTE', fn ($val) => is_string($val) ? trim($val, '"') : $val);
            }
        });

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (\Illuminate\Support\Facades\DB::getDriverName() === 'sqlite') {
            \Illuminate\Support\Facades\DB::connection()->getPdo()->sqliteCreateFunction('JSON_UNQUOTE', fn ($val) => is_string($val) ? trim($val, '"') : $val);
        }
    }
}
