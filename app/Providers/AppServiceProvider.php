<?php

namespace App\Providers;

use App\Services\CurrentCompany;
use App\View\Components\AppLayout;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View as ViewInstance;

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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Livewire renders full-page layouts as views, so it does not instantiate AppLayout as a Blade component.
        View::composer('components.layouts.app', function (ViewInstance $view): void {
            if (! array_key_exists('primaryNavigation', $view->getData())) {
                $view->with(app(AppLayout::class)->data());
            }
        });
    }
}
