<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Tenant defaults (null hasta que el middleware los resuelva)
        $this->app->singleton('current_tenant_id', fn () => null);
        $this->app->singleton('current_tenant', fn () => null);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
