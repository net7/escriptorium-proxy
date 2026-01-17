<?php

namespace App\Providers;

use App\Services\eScriptoriumService;
use Illuminate\Support\ServiceProvider;

class eScriptoriumServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(eScriptoriumService::class, function ($app) {
            return new eScriptoriumService;
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
