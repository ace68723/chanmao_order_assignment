<?php

namespace App\Providers\UserAuthService;

use Illuminate\Support\ServiceProvider;

class UserAuthServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {

        $this->app->singleton('user_auth_service', function ($app) {
            return new UserAuthService(); // You can even put some params here
        });
    }
}
