<?php

test('fase 9 production deployment files exist', function () {
    expect(base_path('docker-compose.prod.yml'))->toBeFile();
    expect(base_path('Dockerfile.prod'))->toBeFile();
    expect(base_path('scripts/deploy.sh'))->toBeFile();
    expect(base_path('scripts/backup-cron.sh'))->toBeFile();
    expect(base_path('.env.production.example'))->toBeFile();
    expect(base_path('DEPLOY.md'))->toBeFile();
    expect(base_path('README.md'))->toBeFile();
    expect(base_path('docs/arquitectura.md'))->toBeFile();
});

test('fase 9 docker configuration files exist', function () {
    expect(base_path('docker/nginx/nginx.conf'))->toBeFile();
    expect(base_path('docker/nginx/default.conf'))->toBeFile();
    expect(base_path('docker/php/opcache.ini'))->toBeFile();
    expect(base_path('docker/php/entrypoint.sh'))->toBeFile();
    expect(base_path('docker/postgres/init.sql'))->toBeFile();
    expect(base_path('docker/ssl/README.md'))->toBeFile();
    expect(base_path('docker/cron/nomina'))->toBeFile();
});

test('fase 9 health controller and production seeder exist', function () {
    expect(app_path('Http/Controllers/HealthController.php'))->toBeFile();
    expect(base_path('database/seeders/ProductionSeeder.php'))->toBeFile();
});
