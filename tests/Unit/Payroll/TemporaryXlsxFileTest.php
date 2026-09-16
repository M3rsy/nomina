<?php

use App\Services\Payroll\TemporaryXlsxFile;

function payrollTempArtifacts(string $prefix): array
{
    return glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.$prefix.'*') ?: [];
}

test('temporary xlsx writer creates only the renamed xlsx reservation', function () {
    $before = payrollTempArtifacts('payroll_temp_test_');

    $path = TemporaryXlsxFile::write('payroll_temp_test_', function (string $path): void {
        expect($path)->toBeFile()
            ->and(filesize($path))->toBe(0)
            ->and(substr($path, 0, -5))->not->toBeFile();

        file_put_contents($path, 'xlsx payload');
    });

    $created = array_values(array_diff(payrollTempArtifacts('payroll_temp_test_'), $before));

    expect($path)->toEndWith('.xlsx')
        ->and($path)->toBeFile()
        ->and(file_get_contents($path))->toBe('xlsx payload')
        ->and($path)->toBeIn($created)
        ->and(substr($path, 0, -5))->not->toBeFile()
        ->and($created)->toHaveCount(1);

    unlink($path);
});

test('temporary xlsx writer removes all artifacts when writing fails', function () {
    $before = payrollTempArtifacts('payroll_temp_fail_');
    $attemptedPath = null;

    expect(function () use (&$attemptedPath): void {
        TemporaryXlsxFile::write('payroll_temp_fail_', function (string $path) use (&$attemptedPath): void {
            $attemptedPath = $path;
            file_put_contents($path, 'partial xlsx payload');

            throw new RuntimeException('writer failed');
        });
    })->toThrow(RuntimeException::class, 'writer failed');

    expect($attemptedPath)->toBeString()
        ->and($attemptedPath)->not->toBeFile()
        ->and(substr((string) $attemptedPath, 0, -5))->not->toBeFile()
        ->and(array_values(array_diff(payrollTempArtifacts('payroll_temp_fail_'), $before)))->toBe([]);
});
