<?php

namespace App\Providers;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Configure Scramble API documentation
        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $openApi) {
                // Set X-API-Key header as default security scheme
                $openApi->secure(
                    SecurityScheme::apiKey('header', 'X-API-Key')
                );

                // Customize Info
                $openApi->info->title = 'eScriptorium Proxy API';
                $openApi->info->version = 'v1';
                $openApi->info->description = config('scramble.info.description');
            });

        // Allow access to API docs in all environments
        // Docs are publicly accessible (change this if you need auth)
        Gate::define('viewApiDocs', function ($user = null) {
            return true;
        });
    }
}
