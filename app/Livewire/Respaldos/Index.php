<?php

namespace App\Livewire\Respaldos;

use App\Models\User;
use App\Providers\AppServiceProvider;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Index extends Component
{
    public string $message = '';

    public string $messageType = 'success';

    public bool $showRestoreModal = false;

    public string $restoreFile = '';

    public function mount(): void
    {
        Gate::allowIf(fn (User $user): bool => AppServiceProvider::canManageGlobalBackups($user));
    }

    public function generate(): void
    {
        Gate::allowIf(fn (User $user): bool => AppServiceProvider::canManageGlobalBackups($user));

        if (! config('backup.enabled', true)) {
            $this->message = 'Los respaldos están deshabilitados en este entorno.';
            $this->messageType = 'danger';

            return;
        }

        try {
            Artisan::call('backup:run', ['--disable-notifications' => true]);

            $this->message = 'Respaldo generado correctamente.';
            $this->messageType = 'success';
        } catch (\Throwable $exception) {
            $this->message = 'Error al generar el respaldo: '.$exception->getMessage();
            $this->messageType = 'danger';
            Log::error('Backup generation failed', ['exception' => $exception]);
        }
    }

    public function confirmRestore(string $file): void
    {
        Gate::authorize('backups.restore');

        $this->restoreFile = $file;
        $this->showRestoreModal = true;
    }

    public function cancelRestore(): void
    {
        $this->showRestoreModal = false;
        $this->restoreFile = '';
    }

    public function restore(): void
    {
        Gate::authorize('backups.restore');

        Log::info('Backup restore blocked in MVP', [
            'file' => $this->restoreFile,
            'user_id' => auth()->id(),
        ]);

        $this->showRestoreModal = false;
        $this->restoreFile = '';
        $this->message = 'Función no implementada en MVP.';
        $this->messageType = 'warning';
    }

    public function render()
    {
        Gate::allowIf(fn (User $user): bool => AppServiceProvider::canManageGlobalBackups($user));

        $disk = Storage::disk('backups');
        $files = collect($disk->allFiles(''))
            ->filter(fn (string $path) => str_ends_with(strtolower($path), '.zip'))
            ->map(fn (string $path) => [
                'path' => $path,
                'name' => basename($path),
                'size' => $this->formatBytes((int) $disk->size($path)),
                'modified' => Carbon::createFromTimestamp($disk->lastModified($path))->format('Y-m-d H:i:s'),
            ])
            ->sortByDesc('modified')
            ->values();

        return view('livewire.respaldos.index', [
            'files' => $files,
            'canRestore' => auth()->user()->can('backups.restore'),
        ]);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $factor = floor(log($bytes, 1024));

        return sprintf('%.2f %s', $bytes / (1024 ** $factor), $units[$factor]);
    }
}
