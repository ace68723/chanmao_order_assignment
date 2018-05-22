<?php

namespace App\Console;

use Log;
use Illuminate\Console\Scheduling\Schedule;
use Laravel\Lumen\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        //
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        //
        $schedule->call(function () {
            Log::DEBUG('running learn_map from console schedule');
            $sche_sp = app()->make('cmoa_schedule_service');
            $ret = $sche_sp->learn_map(1);
        })->everyFiveMinutes()
            ->timezone('America/Toronto')
            ->between('11:00','23:00');
    }
}
