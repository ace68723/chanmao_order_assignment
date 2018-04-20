<?php

namespace App\Providers\ModelCacheService;

use Illuminate\Support\ServiceProvider;

class ModelCacheServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton('cmoa_model_cache_service', function ($app) {
            return new ModelCacheService(); // You can even put some params here
        });
    }
}
