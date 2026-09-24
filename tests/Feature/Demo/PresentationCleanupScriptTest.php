<?php

use Symfony\Component\Process\Process;

function makePresentationCleanupSandbox(): array
{
    $directory = sys_get_temp_dir().'/nomina-presentation-cleanup-'.bin2hex(random_bytes(8));
    mkdir($directory.'/scripts', 0700, true);
    mkdir($directory.'/bin', 0700, true);
    mkdir($directory.'/storage/app/public', 0700, true);
    mkdir($directory.'/storage/logs', 0700, true);

    copy(base_path('scripts/limpiar-presentacion.sh'), $directory.'/scripts/limpiar-presentacion.sh');
    chmod($directory.'/scripts/limpiar-presentacion.sh', 0700);
    file_put_contents($directory.'/.env', implode("\n", [
        'APP_ENV=local',
        'DB_HOST=127.0.0.1',
        'DB_PORT=5432',
        'DB_DATABASE=nomina_demo',
        'DB_USERNAME=nomina_demo',
        'DB_PASSWORD=configured-secret',
        '',
    ]));
    file_put_contents($directory.'/storage/app/public/attendance.txt', 'attendance evidence');
    file_put_contents($directory.'/storage/logs/laravel.log', 'demo log');

    return [$directory, $directory.'/bin'];
}

function removePresentationCleanupSandbox(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $path) {
        $path->isDir() ? @rmdir($path->getPathname()) : @unlink($path->getPathname());
    }

    @rmdir($directory);
}

test('presentation cleanup dry run shows the explicit inventory without deleting data', function (): void {
    [$directory, $bin] = makePresentationCleanupSandbox();

    try {
        $process = new Process(
            ['/bin/sh', 'scripts/limpiar-presentacion.sh', '--dry-run'],
            $directory,
            ['PATH' => $bin.':'.getenv('PATH')],
        );
        $process->mustRun();

        expect($process->getOutput())
            ->toContain('DRY-RUN')
            ->toContain('employees')
            ->toContain('employee_revisions')
            ->toContain('payroll_results')
            ->toContain('raw_marks')
            ->toContain('uploaded_files')
            ->and($directory.'/storage/app/public/attendance.txt')->toBeFile()
            ->and($directory.'/storage/logs/laravel.log')->toBeFile();
    } finally {
        removePresentationCleanupSandbox($directory);
    }
});

test('presentation cleanup truncates only disposable demo tables and uses configured credentials', function (): void {
    [$directory, $bin] = makePresentationCleanupSandbox();
    $capturedSql = $directory.'/bin/captured-sql.txt';
    $capturedArgs = $directory.'/bin/captured-args.txt';

    try {
        file_put_contents($bin.'/psql', "#!/bin/sh\nprintf '%s\n' \"$*\" > \"$capturedArgs\"\ncat > \"$capturedSql\"\n");
        chmod($bin.'/psql', 0700);

        $process = new Process(
            ['/bin/sh', 'scripts/limpiar-presentacion.sh', '--force'],
            $directory,
            ['PATH' => $bin.':'.getenv('PATH')],
        );
        $process->mustRun();

        $sql = file_get_contents($capturedSql);
        $args = file_get_contents($capturedArgs);

        expect($args)
            ->toContain('-h 127.0.0.1')
            ->toContain('-p 5432')
            ->toContain('-U nomina_demo')
            ->toContain('-d nomina_demo')
            ->and($sql)->toContain('payroll_results')
            ->and($sql)->toContain('raw_marks')
            ->and($sql)->toContain('uploaded_files')
            ->and($sql)->toContain('audit_entries')
            ->and($sql)->not->toContain("'employees'")
            ->and($sql)->not->toContain("'employee_revisions'")
            ->and($directory.'/storage/app/public/attendance.txt')->not->toBeFile()
            ->and($directory.'/storage/logs/laravel.log')->not->toBeFile();
    } finally {
        removePresentationCleanupSandbox($directory);
    }
});
