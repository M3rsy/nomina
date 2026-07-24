<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Backup\Config\Config;
use Symfony\Component\Process\Process;

test('files backup preserves immutable attendance evidence without runtime or secret files', function () {
    $sandbox = sys_get_temp_dir().'/nomina-phase6-'.Str::uuid();
    $disk = 'phase6-backups';
    $zip = null;

    $write = function (string $relativePath, string $contents) use ($sandbox): void {
        $path = $sandbox.'/'.$relativePath;

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $contents);
    };

    try {
        $write('app/keep.php', '<?php return true;');
        $write('storage/app/private/uploads/acme/period/evidence.txt', 'immutable-attendance-evidence');
        $write('.env.production', 'APP_KEY=must-not-leak');
        $write('storage/app/nomina-backups/old.zip', 'old-backup');
        $write('storage/app/backup-temp/transient.tmp', 'temporary');
        $write('storage/framework/cache/data/cache-file', 'cache');
        $write('storage/logs/laravel.log', 'runtime-log');
        $write('bootstrap/cache/packages.php', 'cache');
        $write('vendor/package/source.php', 'dependency');
        $write('node_modules/package/index.js', 'dependency');

        $mapPath = fn (string $path): string => Str::startsWith($path, base_path())
            ? $sandbox.Str::after($path, base_path())
            : $path;

        $backupConfig = config('backup');
        $backupConfig['backup']['name'] = 'phase6-completeness';
        $backupConfig['backup']['source']['files']['include'] = array_map(
            $mapPath,
            $backupConfig['backup']['source']['files']['include'],
        );
        $backupConfig['backup']['source']['files']['exclude'] = array_map(
            $mapPath,
            $backupConfig['backup']['source']['files']['exclude'],
        );
        $backupConfig['backup']['source']['files']['relative_path'] = $sandbox;
        $backupConfig['backup']['source']['databases'] = [];
        $backupConfig['backup']['destination']['filename_prefix'] = '';
        $backupConfig['backup']['destination']['disks'] = [$disk];
        $backupConfig['backup']['temporary_directory'] = $sandbox.'/storage/app/backup-temp';
        $backupConfig['backup']['password'] = null;
        $backupConfig['backup']['encryption'] = 'none';

        config([
            "filesystems.disks.{$disk}" => [
                'driver' => 'local',
                'root' => $sandbox.'/storage/app/nomina-backups',
                'throw' => true,
            ],
            'phase6_backup_test' => $backupConfig,
        ]);

        Storage::forgetDisk($disk);
        app()->instance(Config::class, Config::fromArray($backupConfig));

        $exitCode = Artisan::call('backup:run', [
            '--config' => 'phase6_backup_test',
            '--filename' => 'phase6-files.zip',
            '--only-files' => true,
            '--disable-notifications' => true,
        ]);

        expect($exitCode)->toBe(0, Artisan::output());

        $archivePath = collect(Storage::disk($disk)->allFiles())
            ->first(fn (string $path): bool => str_ends_with($path, 'phase6-files.zip'));

        expect($archivePath)->not->toBeNull();

        $zip = new ZipArchive;
        expect($zip->open(Storage::disk($disk)->path($archivePath)))->toBeTrue();

        $entries = collect();

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entries->push($zip->getNameIndex($index));
        }

        expect($entries)
            ->toContain('storage/app/private/uploads/acme/period/evidence.txt')
            ->not->toContain('.env.production')
            ->not->toContain('storage/app/nomina-backups/old.zip')
            ->not->toContain('storage/app/backup-temp/transient.tmp')
            ->not->toContain('storage/framework/cache/data/cache-file')
            ->not->toContain('storage/logs/laravel.log')
            ->not->toContain('bootstrap/cache/packages.php')
            ->not->toContain('vendor/package/source.php')
            ->not->toContain('node_modules/package/index.js');
    } finally {
        if ($zip instanceof ZipArchive) {
            $zip->close();
        }

        Storage::forgetDisk($disk);
        File::deleteDirectory($sandbox);
    }
});

test('the Laravel scheduler owns overlap protected backup maintenance', function () {
    $scheduledCommands = collect(app(Schedule::class)->events());
    $backupCommands = collect();

    foreach ([
        'backup:run' => '0 * * * *',
        'backup:clean' => '15 1 * * *',
        'backup:monitor' => '30 1 * * *',
    ] as $command => $expression) {
        $event = $scheduledCommands->first(
            fn ($event): bool => str_contains((string) $event->command, $command),
        );

        expect($event)->not->toBeNull()
            ->and($event->expression)->toBe($expression)
            ->and($event->withoutOverlapping)->toBeTrue()
            ->and($event->expiresAt)->toBe(120);

        $backupCommands->push($event);
    }

    expect($backupCommands->map->mutexName()->unique()->values()->all())
        ->toBe(['nomina-backup-maintenance']);

    expect(config('backup.monitor_backups.0.disks'))->toBe(['backups']);

    $cron = File::get(base_path('docker/cron/nomina'));

    expect(substr_count($cron, 'schedule:run'))->toBe(1)
        ->and($cron)->not->toContain('backup:run')
        ->not->toContain('backup:clean')
        ->not->toContain('backup:monitor');
});

test('backup maintenance is absent when backups are disabled', function () {
    $process = new Process([PHP_BINARY, 'artisan', 'schedule:list', '--no-ansi'], base_path(), [
        'APP_ENV' => 'testing',
        'BACKUP_ENABLED' => 'false',
        'CACHE_STORE' => 'array',
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => ':memory:',
        'DB_URL' => '',
    ]);

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and($process->getOutput())->not->toContain('backup:');
});
