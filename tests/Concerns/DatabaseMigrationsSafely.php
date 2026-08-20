<?php

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Tests\Support\DatabaseIsolationGuard;

trait DatabaseMigrationsSafely
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        DatabaseIsolationGuard::assertPostgreSqlConnection($this->app);
    }

    public function runDatabaseMigrations(): void
    {
        // Keep the setup sequence aligned with Laravel's DatabaseMigrations trait;
        // only teardown differs because forward-only migrations may reject down().
        $this->beforeRefreshingDatabase();
        $this->refreshTestDatabase();
        $this->afterRefreshingDatabase();

        $this->beforeApplicationDestroyed(function (): void {
            DatabaseIsolationGuard::assertPostgreSqlConnection($this->app);
            $this->artisan('db:wipe', [
                '--drop-views' => true,
                '--drop-types' => true,
                '--force' => true,
            ]);

            RefreshDatabaseState::$migrated = false;
        });
    }
}
