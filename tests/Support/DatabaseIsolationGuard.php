<?php

namespace Tests\Support;

use Illuminate\Contracts\Foundation\Application;
use RuntimeException;

final class DatabaseIsolationGuard
{
    public static function assertPostgreSqlConnection(Application $app): object
    {
        $name = (string) $app['config']->get('database.default');
        $configuration = (array) $app['config']->get("database.connections.{$name}");

        self::assertConfiguration(
            $app->environment(),
            $name,
            $configuration,
            env('NOMINA_ALLOW_DESTRUCTIVE_TEST_DATABASE'),
        );

        $identity = $app['db']->connection($name)->selectOne(
            'select current_database() as database, current_user as username, pg_backend_pid() as pid'
        );
        self::assertLiveIdentity($identity?->database, $identity?->username);

        return $identity;
    }

    public static function assertLiveIdentity(?string $database, ?string $username): void
    {
        if ($database === 'nomina_test' && $username === 'nomina_test') {
            return;
        }

        throw new RuntimeException(
            'Refusing destructive test database setup: the live PostgreSQL identity is not dedicated to tests.'
        );
    }

    public static function assertConfiguration(
        string $environment,
        string $connectionName,
        array $configuration,
        ?string $token,
    ): void {
        if ($environment === 'testing'
            && $connectionName === 'sqlite'
            && ($configuration['driver'] ?? null) === 'sqlite'
            && ($configuration['database'] ?? null) === ':memory:'
            && in_array($configuration['url'] ?? null, [null, ''], true)) {
            return;
        }

        $endpoint = [
            $configuration['host'] ?? null,
            (string) ($configuration['port'] ?? ''),
        ];
        $approvedEndpoints = [
            ['nomina-test-db', '5432'],
            ['127.0.0.1', '55432'],
        ];

        if ($environment === 'testing'
            && $connectionName === 'pgsql_testing'
            && ($configuration['driver'] ?? null) === 'pgsql'
            && ! array_key_exists('url', $configuration)
            && ($configuration['database'] ?? null) === 'nomina_test'
            && ($configuration['username'] ?? null) === 'nomina_test'
            && in_array($endpoint, $approvedEndpoints, true)
            && $token === 'nomina_test@postgresql-v1') {
            return;
        }

        throw new RuntimeException(
            'Refusing destructive test database setup: the dedicated PostgreSQL test identity is not fully isolated.'
        );
    }

    public static function assertIsolated(?string $driver, ?string $database): void
    {
        if ($driver === 'sqlite' && $database === ':memory:') {
            return;
        }

        throw new RuntimeException(
            'Refusing destructive test database setup: only the resolved sqlite ":memory:" identity is isolated.'
        );
    }
}
