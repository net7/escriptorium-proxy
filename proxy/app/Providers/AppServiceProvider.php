<?php

namespace App\Providers;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
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
        // Force HTTPS in deployed environments (staging, production)
        if (app()->environment(['staging', 'production'])) {
            URL::forceScheme('https');
        }

        // Configure Scramble API documentation
        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $openApi) {
                // Set X-API-Key header as default security scheme
                $openApi->secure(
                    SecurityScheme::apiKey('header', 'X-API-Key')
                        ->setDescription(<<<'DESC'
                        Chiave API per autenticazione. Supporta due modalità:

                        **1. API Key Proxy** - Chiave generata dal sistema proxy
                        - Progetti temporanei (auto-eliminati al termine dell'elaborazione)
                        - Ideale per integrazioni automatiche e test
                        - Non richiede un account eScriptorium

                        **2. API Key eScriptorium** - Chiave personale dal tuo account eScriptorium
                        - Progetti persistenti **sul tuo account eScriptorium personale**
                        - Puoi accedere ai progetti direttamente da eScriptorium
                        - Supporta riuso di progetti/documenti esistenti (Find or Create)
                        - Ottieni la chiave da: eScriptorium → Profile → API Token
                        DESC)
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

        $this->configureSecureUrls();
    }

    protected function configureSecureUrls()
    {
        // Determine if HTTPS should be enforced
        $enforceHttps = $this->app->environment(['production', 'staging'])
            && ! $this->app->runningUnitTests();

        // Force HTTPS for all generated URLs
        URL::forceHttps($enforceHttps);

        // Ensure proper server variable is set
        if ($enforceHttps) {
            $this->app['request']->server->set('HTTPS', 'on');
        }

        // Set up global middleware for security headers
        if ($enforceHttps) {
            $this->app['router']->pushMiddlewareToGroup('web', function ($request, $next) {
                $response = $next($request);

                return $response->withHeaders([
                    'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
                    'Content-Security-Policy' => 'upgrade-insecure-requests',
                    'X-Content-Type-Options' => 'nosniff',
                ]);
            });
        }
    }
}
