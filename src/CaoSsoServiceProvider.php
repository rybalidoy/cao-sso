<?php

namespace Cao\LaravelCaoSso;

use Cao\LaravelCaoSso\Http\Controllers\SsoController;
use Cao\LaravelCaoSso\Providers\CaoProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Socialite\Facades\Socialite;

class CaoSsoServiceProvider extends ServiceProvider
{
    /**
     * Register the package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/cao-sso.php', 'cao-sso');
    }

    /**
     * Bootstrap the package services.
     */
    public function boot(): void
    {
        $this->registerSocialiteDriver();
        $this->registerRoutes();
        $this->registerPublishing();
    }

    /**
     * Register the "cao" Socialite driver.
     */
    protected function registerSocialiteDriver(): void
    {
        Socialite::extend('cao', function ($app) {
            return (new CaoProvider(
                $app['request'],
                config('cao-sso.client_id'),
                config('cao-sso.client_secret'),
                config('cao-sso.redirect'),
            ))->scopes(config('cao-sso.scopes', []));
        });
    }

    /**
     * Register the SSO login + callback routes.
     */
    protected function registerRoutes(): void
    {
        if (! config('cao-sso.routes.enabled', true)) {
            return;
        }

        Route::middleware(config('cao-sso.routes.middleware', ['web']))
            ->prefix(config('cao-sso.routes.prefix', 'api/auth'))
            ->group(function () {
                Route::get(config('cao-sso.routes.login_path', 'sso/cao'), [SsoController::class, 'redirectToCao'])
                    ->name('cao-sso.login');

                Route::get(config('cao-sso.routes.callback_path', 'callback'), [SsoController::class, 'handleCaoCallback'])
                    ->name('cao-sso.callback');
            });
    }

    /**
     * Register the publishable config + migration.
     */
    protected function registerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/cao-sso.php' => config_path('cao-sso.php'),
        ], 'cao-sso-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'cao-sso-migrations');

        $this->publishes([
            __DIR__.'/../config/cao-sso.php' => config_path('cao-sso.php'),
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'cao-sso');
    }
}
