<?php

namespace App\Providers\ScheduleService;

use Illuminate\Support\ServiceProvider;

class ScheduleServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton('cmoa_schedule_service', function ($app) {
            return new ScheduleService(); // You can even put some params here
        });
    }
}
