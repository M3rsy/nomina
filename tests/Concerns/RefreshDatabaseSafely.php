<?php

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DatabaseIsolationGuard;

trait RefreshDatabaseSafely
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        $connection = $this->app['db']->connection();

        DatabaseIsolationGuard::assertIsolated(
            $connection->getDriverName(),
            $connection->getDatabaseName(),
        );
    }
}
