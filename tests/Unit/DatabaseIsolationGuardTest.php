<?php

use Tests\Concerns\RefreshDatabaseSafely;
use Tests\Support\DatabaseIsolationGuard;

it('accepts the isolated in-memory SQLite identity', function () {
    expect(fn () => DatabaseIsolationGuard::assertIsolated('sqlite', ':memory:'))
        ->not->toThrow(RuntimeException::class);
});

it('forces the canonical identity over inherited process variables', function () {
    expect(getenv('DB_CONNECTION'))->toBe('sqlite')
        ->and($_ENV['DB_CONNECTION'] ?? null)->toBe('sqlite')
        ->and($_SERVER['DB_CONNECTION'] ?? null)->toBe('sqlite')
        ->and($_SERVER['DB_DATABASE'] ?? null)->toBe(':memory:')
        ->and($_SERVER['DB_URL'] ?? null)->toBe('');
});

it('rejects the development PostgreSQL database even in the testing environment', function () {
    expect(getenv('APP_ENV'))->toBe('testing')
        ->and(fn () => DatabaseIsolationGuard::assertIsolated('pgsql', 'nomina'))
        ->toThrow(RuntimeException::class);
});

it('rejects blank or ambiguous resolved identities', function (?string $driver, ?string $database) {
    expect(fn () => DatabaseIsolationGuard::assertIsolated($driver, $database))
        ->toThrow(RuntimeException::class);
})->with([
    'missing identity' => [null, null],
    'blank driver' => ['', ':memory:'],
    'blank database' => ['sqlite', '  '],
]);

it('rejects file-backed SQLite databases', function () {
    expect(fn () => DatabaseIsolationGuard::assertIsolated('sqlite', '/tmp/nomina-tests.sqlite'))
        ->toThrow(RuntimeException::class);
});

it('rejects database identities whose isolation depends on a naming convention', function () {
    expect(fn () => DatabaseIsolationGuard::assertIsolated('pgsql', 'nomina_test'))
        ->toThrow(RuntimeException::class);
});

it('guards the resolved identity before destructive database setup', function () {
    if (! trait_exists(RefreshDatabaseSafely::class)) {
        expect(false)->toBeTrue();

        return;
    }

    $testCase = new class
    {
        use RefreshDatabaseSafely;

        public array $app;

        public bool $destructiveSetupRan = false;

        public function __construct()
        {
            $this->app = ['db' => new class
            {
                public function connection(): object
                {
                    return new class
                    {
                        public function getDriverName(): string
                        {
                            return 'pgsql';
                        }

                        public function getDatabaseName(): string
                        {
                            return 'nomina';
                        }
                    };
                }
            }];
        }

        protected function refreshTestDatabase(): void
        {
            $this->destructiveSetupRan = true;
        }
    };

    expect(fn () => $testCase->refreshDatabase())->toThrow(RuntimeException::class)
        ->and($testCase->destructiveSetupRan)->toBeFalse();
});
