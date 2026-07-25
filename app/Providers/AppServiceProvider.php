<?php

namespace App\Providers;

use App\Models\User;
use App\Services\BackupArchiveVerifier;
use App\Services\CurrentCompany;
use App\View\Components\AppLayout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View as ViewInstance;
use Spatie\Backup\Events\BackupManifestWasCreated;
use Spatie\Backup\Events\BackupZipWasCreated;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CurrentCompany::class, function (): CurrentCompany {
            return new CurrentCompany;
        });
        $this->app->singleton(BackupArchiveVerifier::class);

        if ($this->shouldUseFileCacheForMaintenanceCommands()) {
            config(['cache.default' => 'file']);
        }
    }

    public static function canManageGlobalBackups(User $user): bool
    {
        return $user->hasRole('super_admin') && $user->hasPermissionTo('backups.run');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(BackupArchiveVerifier $backupArchiveVerifier): void
    {
        Event::listen(BackupManifestWasCreated::class, [$backupArchiveVerifier, 'captureManifest']);
        Event::listen(BackupZipWasCreated::class, [$backupArchiveVerifier, 'verifyArchive']);

        Gate::define('backups.manage-global', fn (User $user): bool => self::canManageGlobalBackups($user));

        // Livewire renders full-page layouts as views, so it does not instantiate AppLayout as a Blade component.
        View::composer('components.layouts.app', function (ViewInstance $view): void {
            if (! array_key_exists('primaryNavigation', $view->getData())) {
                $view->with(app(AppLayout::class)->data());
            }
        });
    }

    private function shouldUseFileCacheForMaintenanceCommands(): bool
    {
        if (! $this->app->runningInConsole()) {
            return false;
        }

        if (extension_loaded('pdo_pgsql')) {
            return false;
        }

        if (! $this->isDockerOrCiContext()) {
            return false;
        }

        if (! $this->isMaintenanceCommand()) {
            return false;
        }

        return true;
    }

    private function isDockerOrCiContext(): bool
    {
        if (file_exists('/.dockerenv')) {
            return true;
        }

        $environmentFlags = [
            env('CI'),
            env('GITHUB_ACTIONS'),
            env('GITLAB_CI'),
            env('TRAVIS'),
            env('CIRCLECI'),
            env('BUILDKITE'),
            env('DRONE'),
        ];

        foreach ($environmentFlags as $flag) {
            if (is_string($flag) && filter_var($flag, FILTER_VALIDATE_BOOLEAN)) {
                return true;
            }
        }

        return false;
    }

    private function isMaintenanceCommand(): bool
    {
        $command = $_SERVER['argv'][1] ?? null;

        return is_string($command)
            && in_array($command, [
                'cache:clear',
                'config:clear',
                'route:clear',
                'view:clear',
            ], true);
    }
}
