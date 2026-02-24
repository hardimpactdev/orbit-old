<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\ConfigManager;
use HardImpact\Orbit\Core\Services\Gateway\WgEasyService;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // WgEasyService needs explicit config from ConfigManager
        $this->app->bind(WgEasyService::class, function ($app) {
            $cm = $app->make(ConfigManager::class);

            return new WgEasyService(
                host: $cm->get('wg_easy.host', '127.0.0.1'),
                port: (int) $cm->get('wg_easy.web_ui_port', 51821),
                password: $cm->get('wg_easy.password', ''),
            );
        });

        // Register HTTP client factory
        $this->app->singleton(Factory::class, fn ($app) => new Factory);

        // Alias for facade
        $this->app->alias(Factory::class, 'http');

    }
}
