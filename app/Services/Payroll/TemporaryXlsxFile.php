<?php

namespace App\Services\Payroll;

use Illuminate\Filesystem\Filesystem;

final class TemporaryXlsxFile
{
    /**
     * @param  callable(string): void  $write
     */
    public static function write(string $prefix, callable $write): string
    {
        $reservation = tempnam(sys_get_temp_dir(), $prefix);

        if ($reservation === false) {
            throw new \RuntimeException('Unable to reserve a temporary XLSX file.');
        }

        $path = $reservation.'.xlsx';

        if (! @rename($reservation, $path)) {
            $cleanupFailure = self::cleanupBestEffort($reservation);
            $message = 'Unable to prepare temporary XLSX file by renaming the reserved file.';

            if ($cleanupFailure !== null) {
                $message .= ' '.$cleanupFailure->getMessage();
            }

            throw new \RuntimeException($message);
        }

        try {
            $write($path);

            return $path;
        } catch (\Throwable $throwable) {
            $cleanupFailure = self::cleanupBestEffort($path, $reservation);

            if ($cleanupFailure !== null) {
                try {
                    report($cleanupFailure);
                } catch (\Throwable) {
                    // Reporting cleanup failure must not mask the writer exception.
                }
            }

            throw $throwable;
        }
    }

    private static function cleanupBestEffort(string ...$paths): ?\RuntimeException
    {
        $failures = [];

        foreach ($paths as $path) {
            clearstatcache(true, $path);

            if (! is_file($path)) {
                continue;
            }

            try {
                if ((new Filesystem)->delete($path) === false) {
                    $failures[] = $path;
                }
            } catch (\Throwable) {
                $failures[] = $path;
            }
        }

        if ($failures === []) {
            return null;
        }

        return new \RuntimeException('Failed to remove temporary XLSX artifact(s): '.implode(', ', $failures));
    }
}
