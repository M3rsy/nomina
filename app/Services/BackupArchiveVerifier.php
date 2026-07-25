<?php

namespace App\Services;

use RuntimeException;
use Spatie\Backup\Config\Config;
use Spatie\Backup\Events\BackupManifestWasCreated;
use Spatie\Backup\Events\BackupZipWasCreated;
use ZipArchive;

final class BackupArchiveVerifier
{
    private ?array $expectedEntries = null;

    public function captureManifest(BackupManifestWasCreated $event): void
    {
        $this->expectedEntries = [];

        foreach ($event->manifest->files() as $path) {
            $file = is_file($path);
            $this->expectedEntries[] = [
                'path' => $path,
                'size' => $file ? filesize($path) : null,
                'crc' => $file ? hash_file('crc32b', $path) : null,
            ];
        }
    }

    public function verifyArchive(BackupZipWasCreated $event): void
    {
        $expectedEntries = $this->expectedEntries ?? throw new RuntimeException('Backup verification has no manifest.');
        $this->expectedEntries = null;

        $zip = new ZipArchive;
        if ($zip->open($event->pathToZip, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Backup verification could not open the archive.');
        }
        try {
            if ($zip->numFiles !== count($expectedEntries)) {
                throw new RuntimeException('Backup verification found an incomplete archive.');
            }
            $password = app(Config::class)->backup->password;
            if (! is_string($password) || $password === '') {
                throw new RuntimeException('Backup verification requires archive encryption.');
            }
            $zip->setPassword($password);
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $expected = $expectedEntries[$index];
                $entry = $zip->statIndex($index);
                if ($entry === false || $entry['name'] !== $this->archiveName($expected['path'], $event->pathToZip).($expected['crc'] === null ? '/' : '')) {
                    throw new RuntimeException('Backup verification found an unexpected archive entry.');
                }
                if ($expected['crc'] === null) {
                    continue;
                }
                $stream = $zip->getStream($entry['name']);
                if ($stream === false) {
                    throw new RuntimeException('Backup verification could not decrypt an archive entry.');
                }
                $hash = hash_init('crc32b');
                $bytesRead = hash_update_stream($hash, $stream);
                fclose($stream);
                if ($bytesRead !== $expected['size'] || hash_final($hash) !== $expected['crc']) {
                    throw new RuntimeException('Backup verification found a corrupt archive entry.');
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function archiveName(string $path, string $zipPath): string
    {
        $directory = pathinfo($path, PATHINFO_DIRNAME).DIRECTORY_SEPARATOR;
        $zipDirectory = pathinfo($zipPath, PATHINFO_DIRNAME).DIRECTORY_SEPARATOR;
        if (str_starts_with($directory, $zipDirectory)) {
            return ltrim(substr($path, strlen($zipDirectory)), DIRECTORY_SEPARATOR);
        }
        $relativePath = app(Config::class)->backup->source->files->relativePath;
        $relativePath = $relativePath ? rtrim($relativePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR : null;
        $name = $relativePath && $relativePath !== DIRECTORY_SEPARATOR && str_starts_with($directory, $relativePath)
            ? substr($path, strlen($relativePath))
            : $path;

        return ltrim($name, DIRECTORY_SEPARATOR);
    }
}
