<?php

use App\Services\BackupArchiveVerifier;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Backup\Config\Config;
use Spatie\Backup\Events\BackupZipWasCreated;
use Symfony\Component\Process\Process;

test('backup archives use AES-256 encryption and built-in verification', function () {
    expect(config('backup.backup.encryption'))->toBe('aes256')
        ->and(config('backup.backup.verify_backup'))->toBeTrue();
});

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
        $backupConfig['backup']['password'] = 'phase7-test-passphrase';

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

        $evidencePath = 'storage/app/private/uploads/acme/period/evidence.txt';
        expect($zip->getFromName($evidencePath))->toBeFalse();
        $zip->setPassword('phase7-test-passphrase');
        expect($zip->getFromName($evidencePath))->toBe('immutable-attendance-evidence');

        $entries = collect();

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entries->push($zip->getNameIndex($index));
        }

        expect($entries)
            ->toContain($evidencePath)
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

test('integrity verification rejects unsafe archives before storage', function (?string $password, string $tamper) {
    $sandbox = sys_get_temp_dir().'/nomina-phase7-corrupt-'.Str::uuid();
    $disk = 'phase7-corrupt-backups';

    try {
        File::ensureDirectoryExists($sandbox.'/source');
        File::put($sandbox.'/source/evidence.txt', 'evidence');
        File::put($sandbox.'/source/retained.txt', 'retained');

        $backupConfig = config('backup');
        $backupConfig['backup']['name'] = 'phase7-integrity';
        $backupConfig['backup']['source']['files']['include'] = [$sandbox.'/source'];
        $backupConfig['backup']['source']['files']['exclude'] = [];
        $backupConfig['backup']['source']['files']['relative_path'] = $sandbox;
        $backupConfig['backup']['source']['databases'] = [];
        $backupConfig['backup']['destination']['filename_prefix'] = '';
        $backupConfig['backup']['destination']['compression_method'] = ZipArchive::CM_STORE;
        $backupConfig['backup']['destination']['disks'] = [$disk];
        $backupConfig['backup']['temporary_directory'] = $sandbox.'/temporary';
        $backupConfig['backup']['password'] = $password;

        config([
            "filesystems.disks.{$disk}" => [
                'driver' => 'local',
                'root' => $sandbox.'/destination',
                'throw' => true,
            ],
            'phase7_integrity_test' => $backupConfig,
        ]);

        Storage::forgetDisk($disk);
        app()->instance(Config::class, Config::fromArray($backupConfig));
        Event::forget(BackupZipWasCreated::class);
        Event::listen(BackupZipWasCreated::class, function (BackupZipWasCreated $event) use ($tamper): void {
            $bytes = File::get($event->pathToZip);
            $header = unpack('vname_length/vextra_length', substr($bytes, 26, 4));
            $offset = 30 + $header['name_length'] + $header['extra_length'] + 20;
            $bytes = match ($tamper) {
                'payload' => substr_replace($bytes, chr(ord($bytes[$offset]) ^ 1), $offset, 1),
                'rename' => str_replace('evidence.txt', 'renamed_.txt', $bytes),
                default => $bytes,
            };
            File::put($event->pathToZip, $bytes);

            $zip = new ZipArchive;
            expect($zip->open($event->pathToZip, ZipArchive::RDONLY))->toBeTrue()
                ->and($zip->numFiles)->toBe(2);
            $zip->close();
        });
        Event::listen(BackupZipWasCreated::class, [app(BackupArchiveVerifier::class), 'verifyArchive']);

        $exitCode = Artisan::call('backup:run', [
            '--config' => 'phase7_integrity_test',
            '--filename' => 'corrupt.zip',
            '--only-files' => true,
            '--disable-notifications' => true,
        ]);

        expect($exitCode)->not->toBe(0)
            ->and(Storage::disk($disk)->allFiles())->toBe([]);
    } finally {
        Storage::forgetDisk($disk);
        File::deleteDirectory($sandbox);
    }
})->with([
    'payload corruption' => ['phase7-test-passphrase', 'payload'],
    'entry rename' => ['phase7-test-passphrase', 'rename'],
    'missing archive password' => [null, 'none'],
]);

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
