<?php

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\Support\DatabaseIsolationGuard;

trait DatabaseMigrationsSafely
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        DatabaseIsolationGuard::assertPostgreSqlConnection($this->app);
    }
}
