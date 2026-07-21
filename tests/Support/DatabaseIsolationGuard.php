<?php

namespace Tests\Support;

use RuntimeException;

final class DatabaseIsolationGuard
{
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
