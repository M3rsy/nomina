<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

if (config('backup.enabled', true)) {
    $backupMaintenanceMutex = 'nomina-backup-maintenance';

    Schedule::command('backup:run')->hourly()->withoutOverlapping(120)->createMutexNameUsing($backupMaintenanceMutex);
    Schedule::command('backup:clean')->dailyAt('01:15')->withoutOverlapping(120)->createMutexNameUsing($backupMaintenanceMutex);
    Schedule::command('backup:monitor')->dailyAt('01:30')->withoutOverlapping(120)->createMutexNameUsing($backupMaintenanceMutex);
}
