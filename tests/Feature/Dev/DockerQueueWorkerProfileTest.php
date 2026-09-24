<?php

use Symfony\Component\Yaml\Yaml;

function renderDevelopmentCompose(): array
{
    return Yaml::parseFile(base_path('docker-compose.yml'));
}

test('development compose exposes an opt in queue worker profile', function (): void {
    $compose = renderDevelopmentCompose();
    $app = $compose['services']['app'];
    $worker = $compose['services']['worker'];

    expect($worker['profiles'])->toBe(['worker'])
        ->and($worker['build'])->toBe($app['build'])
        ->and($worker['environment'])->toBe($app['environment'])
        ->and($worker['volumes'])->toBe($app['volumes'])
        ->and($worker['networks'])->toBe($app['networks'])
        ->and($worker['depends_on'])->toBe(['db'])
        ->and($worker['restart'])->toBe('unless-stopped')
        ->and($worker['stop_grace_period'])->toBe('5m')
        ->and($worker)->not->toHaveKey('ports')
        ->and($worker['command'])->toBe([
            'php',
            'artisan',
            'queue:work',
            '--sleep=3',
            '--tries=30',
            '--timeout=240',
            '--max-time=3600',
        ]);
});

test('development compose publishes only explicit loopback host ports', function (): void {
    $compose = renderDevelopmentCompose();

    expect($compose['services']['app']['ports'])->toBe(['127.0.0.1:8000:8000'])
        ->and($compose['services']['db']['ports'])->toBe(['127.0.0.1:5432:5432'])
        ->and($compose['services']['nomina-test-db']['ports'])->toBe(['127.0.0.1:55432:5432']);
});

test('development queue settings keep database reservations beyond worker timeout', function (): void {
    $compose = renderDevelopmentCompose();
    $environment = $compose['services']['app']['environment'];

    expect($environment['QUEUE_CONNECTION'])->toBe('database')
        ->and((int) $environment['DB_QUEUE_RETRY_AFTER'])->toBeGreaterThan(240)
        ->and((int) $environment['DB_QUEUE_RETRY_AFTER'])->toBe(360);
});
