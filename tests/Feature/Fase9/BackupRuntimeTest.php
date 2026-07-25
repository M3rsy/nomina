<?php

use Symfony\Component\Process\Process;

test('the app and scheduler share the production backup volume', function () {
    $compose = renderPhaseFiveProductionCompose();
    $backupPath = '/var/www/html/storage/app/nomina-backups';

    $appVolume = findPhaseFiveVolume($compose['services']['app']['volumes'], $backupPath);
    $schedulerVolume = findPhaseFiveVolume($compose['services']['scheduler']['volumes'], $backupPath);

    expect($appVolume)->toMatchArray([
        'type' => 'volume',
        'source' => 'nomina-backups',
        'target' => $backupPath,
    ])->and($schedulerVolume)->toMatchArray([
        'type' => 'volume',
        'source' => 'nomina-backups',
        'target' => $backupPath,
    ]);
});

test('the scheduler uses the shared entrypoint and installs the www-data crontab', function () {
    $scheduler = renderPhaseFiveProductionCompose()['services']['scheduler'];

    expect($scheduler['entrypoint'])->toBeNull()
        ->and($scheduler['environment']['RUN_APP_BOOTSTRAP'] ?? null)->toBe('false')
        ->and($scheduler['command'])->toBe([
            '/bin/sh',
            '-c',
            'crontab -u www-data /etc/cron.d/nomina && exec crond -f -l 2',
        ])
        ->and(findPhaseFiveVolume($scheduler['volumes'], '/etc/cron.d/nomina'))->toMatchArray([
            'type' => 'bind',
            'target' => '/etc/cron.d/nomina',
            'read_only' => true,
        ]);
});

test('the production entrypoint defaults safely and can skip app bootstrap for the scheduler', function () {
    $directory = sys_get_temp_dir().'/nomina-entrypoint-'.bin2hex(random_bytes(8));
    $marker = $directory.'/injected';
    $activity = $directory.'/activity';
    mkdir($directory, 0700);

    try {
        writePhaseFiveExecutable($directory, 'chown', "#!/bin/sh\nprintf 'chown\\n' >> ".escapeshellarg($activity)."\n");
        writePhaseFiveExecutable($directory, 'chmod', "#!/bin/sh\nprintf 'chmod\\n' >> ".escapeshellarg($activity)."\n");
        writePhaseFiveExecutable($directory, 'php', "#!/bin/sh\nprintf 'php\\n' >> ".escapeshellarg($activity)."\n");
        writePhaseFiveExecutable($directory, 'su-exec', "#!/bin/sh\nshift\nexec \"\$@\"\n");
        writePhaseFiveExecutable($directory, 'php-fpm', "#!/bin/sh\nprintf 'php-fpm-default\\n'\n");
        writePhaseFiveExecutable(
            $directory,
            'phase-five-probe',
            "#!/bin/sh\nprintf 'argc=%s\\n' \"\$#\"\nprintf '<%s>\\n' \"\$@\"\n",
        );

        $entrypoint = dirname(__DIR__, 3).'/docker/php/entrypoint.sh';
        $environment = [
            'PATH' => $directory.':'.getenv('PATH'),
            'BACKUP_ARCHIVE_PASSWORD' => 'phase7-test-passphrase',
        ];

        $default = new Process([$entrypoint], env: $environment);
        $default->mustRun();

        file_put_contents($activity, '');
        $payload = 'literal; touch '.$marker;
        $explicit = new Process(
            [$entrypoint, 'phase-five-probe', $payload],
            env: [...$environment, 'RUN_APP_BOOTSTRAP' => 'false'],
        );
        $explicit->mustRun();

        expect($default->getOutput())->toBe("php-fpm-default\n")
            ->and($explicit->getOutput())->toBe("argc=1\n<{$payload}>\n")
            ->and(file_get_contents($activity))->toBe("chown\nchmod\n")
            ->and($marker)->not->toBeFile();
    } finally {
        foreach (glob($directory.'/*') ?: [] as $path) {
            @unlink($path);
        }

        @rmdir($directory);
    }
});

test('the production runtime stops before bootstrap when the archive password is missing', function () {
    $directory = sys_get_temp_dir().'/nomina-entrypoint-preflight-'.bin2hex(random_bytes(8));
    $activity = $directory.'/activity';
    mkdir($directory, 0700);

    try {
        foreach (['chown', 'chmod', 'phase-seven-probe'] as $command) {
            writePhaseFiveExecutable($directory, $command, "#!/bin/sh\nprintf '{$command}\\n' >> ".escapeshellarg($activity)."\n");
        }

        $entrypoint = dirname(__DIR__, 3).'/docker/php/entrypoint.sh';
        $process = new Process(
            [$entrypoint, 'phase-seven-probe'],
            env: [
                'PATH' => $directory.':'.getenv('PATH'),
                'RUN_APP_BOOTSTRAP' => 'false',
            ],
        );
        $process->run();

        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getOutput().$process->getErrorOutput())->toContain('BACKUP_ARCHIVE_PASSWORD')
            ->and($activity)->not->toBeFile();
    } finally {
        foreach (glob($directory.'/*') ?: [] as $path) {
            @unlink($path);
        }

        @rmdir($directory);
    }
});

test('the production image installs the PostgreSQL runtime client package', function () {
    $dockerfile = file_get_contents(dirname(__DIR__, 3).'/Dockerfile.prod');

    expect($dockerfile)->toMatch('/RUN apk add --no-cache(?:(?!&&).)*\bpostgresql-client\b/s');
});

test('the production Docker context excludes runtime environment files', function () {
    expect(base_path('.dockerignore'))->toBeFile();

    $patterns = file(base_path('.dockerignore'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    expect($patterns)->toContain('bootstrap/cache/*.php')
        ->toContain('.env*', '!.env.example', '!.env.production.example');
});

test('deploy stops before repository mutation when the archive password is missing', function () {
    $directory = sys_get_temp_dir().'/nomina-deploy-preflight-'.bin2hex(random_bytes(8));
    $scripts = $directory.'/scripts';
    $bin = $directory.'/bin';
    $gitActivity = $directory.'/git-activity';
    mkdir($scripts, 0700, true);
    mkdir($bin, 0700);

    try {
        copy(dirname(__DIR__, 3).'/scripts/deploy.sh', $scripts.'/deploy.sh');
        chmod($scripts.'/deploy.sh', 0700);
        file_put_contents($directory.'/.env.production', implode("\n", [
            'DOMAIN=example.test',
            'DB_PASSWORD=database-secret',
            'APP_KEY=base64:application-key',
            '',
        ]));
        writePhaseFiveExecutable($bin, 'git', "#!/bin/sh\nprintf 'git\\n' >> ".escapeshellarg($gitActivity)."\n");

        $process = new Process(
            [$scripts.'/deploy.sh'],
            $directory,
            ['PATH' => $bin.':'.getenv('PATH'), 'BACKUP_ARCHIVE_PASSWORD' => 'inherited-must-not-count'],
        );
        $process->run();

        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getOutput().$process->getErrorOutput())->toContain('BACKUP_ARCHIVE_PASSWORD')
            ->and($gitActivity)->not->toBeFile();
    } finally {
        foreach (glob($bin.'/*') ?: [] as $path) {
            @unlink($path);
        }

        @unlink($directory.'/.env.production');
        @unlink($scripts.'/deploy.sh');
        @rmdir($bin);
        @rmdir($scripts);
        @rmdir($directory);
    }
});

/**
 * @return array<string, mixed>
 */
function renderPhaseFiveProductionCompose(): array
{
    $directory = sys_get_temp_dir().'/nomina-compose-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);

    try {
        copy(dirname(__DIR__, 3).'/docker-compose.prod.yml', $directory.'/docker-compose.prod.yml');
        touch($directory.'/.env.production');

        $process = new Process([
            'docker',
            'compose',
            '--project-directory',
            $directory,
            '-f',
            $directory.'/docker-compose.prod.yml',
            'config',
            '--format',
            'json',
        ]);
        $process->mustRun();

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    } finally {
        @unlink($directory.'/.env.production');
        @unlink($directory.'/docker-compose.prod.yml');
        @rmdir($directory);
    }
}

/**
 * @param  array<int, array<string, mixed>>  $volumes
 * @return array<string, mixed>|null
 */
function findPhaseFiveVolume(array $volumes, string $target): ?array
{
    foreach ($volumes as $volume) {
        if (($volume['target'] ?? null) === $target) {
            return $volume;
        }
    }

    return null;
}

function writePhaseFiveExecutable(string $directory, string $name, string $contents): void
{
    $path = $directory.'/'.$name;
    file_put_contents($path, $contents);
    chmod($path, 0700);
}
