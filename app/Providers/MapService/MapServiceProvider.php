<?php

namespace App\Providers\MapService;

use Illuminate\Support\ServiceProvider;

class MapServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton('cmoa_map_service', function ($app) {
            return new MapService(); // You can even put some params here
        });
    }
}
