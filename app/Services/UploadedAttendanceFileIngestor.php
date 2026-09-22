<?php

namespace App\Services;

use App\Models\Company;
use App\Models\PayPeriod;
use App\Models\UploadedFile;
use App\Models\User;
use App\Services\Parsers\ParserFactory;
use Illuminate\Http\UploadedFile as HttpUploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class UploadedAttendanceFileIngestor
{
    public function __construct(private FileValidator $validator) {}

    public function ingest(Company $company, PayPeriod $payPeriod, User $user, HttpUploadedFile $file): UploadedFile
    {
        $originalName = $file->getClientOriginalName();
        $parser = ParserFactory::make($originalName);
        $extension = strtolower($file->getClientOriginalExtension());
        $storedName = strtolower((string) Str::ulid()).'.'.$extension;
        $relativePath = "uploads/{$company->slug}/{$payPeriod->slug}/{$storedName}";
        $path = null;

        try {
            $storedPath = $file->storeAs(dirname($relativePath), basename($relativePath), 'local');

            if (! is_string($storedPath) || $storedPath === '') {
                throw new RuntimeException('Attendance upload could not be stored.');
            }

            $path = $storedPath;
            $fullPath = Storage::disk('local')->path($path);
            $sha256 = hash_file('sha256', $fullPath);

            $existing = UploadedFile::where('company_id', $company->id)
                ->where('sha256', $sha256)
                ->first();

            if ($existing !== null) {
                throw $this->duplicateUploadException();
            }

            return DB::transaction(function () use ($company, $payPeriod, $user, $file, $parser, $originalName, $extension, $storedName, $path, $sha256): UploadedFile {
                $contents = Storage::disk('local')->get($path);
                $encoding = mb_detect_encoding($contents, ['ASCII', 'UTF-8'], true) ?: 'ASCII';

                $uploadedFile = UploadedFile::create([
                    'company_id' => $company->id,
                    'pay_period_id' => $payPeriod->id,
                    'original_name' => $originalName,
                    'stored_name' => $storedName,
                    'disk' => 'local',
                    'path' => $path,
                    'mime' => $file->getMimeType(),
                    'extension' => $extension,
                    'size_bytes' => $file->getSize(),
                    'encoding' => $encoding,
                    'sha256' => $sha256,
                    'status' => 'pending',
                    'user_id' => $user->id,
                    'validation_summary' => null,
                ]);

                $parsedFile = $parser->parse($contents);

                $this->validator->validate($uploadedFile, $parsedFile->records);

                return $uploadedFile;
            });
        } catch (Throwable $throwable) {
            $this->cleanupStoredPath($path);

            throw $throwable;
        }
    }

    private function cleanupStoredPath(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        try {
            Storage::disk('local')->delete($path);
        } catch (Throwable $cleanupFailure) {
            report($cleanupFailure);
        }
    }

    private function duplicateUploadException(): ValidationException
    {
        return ValidationException::withMessages([
            'upload' => 'Este archivo ya fue cargado anteriormente.',
        ]);
    }
}
