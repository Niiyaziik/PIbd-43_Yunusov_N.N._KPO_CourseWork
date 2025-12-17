<?php

namespace App\Providers;

use Illuminate\Console\Scheduling\Schedule;
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
        // Планировщик задач для автоматического обновления CSV
        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);
            
            // Обновляем данные каждый день в 20:00
            $schedule->command('securities:update-csv')
                ->dailyAt('20:00')
                ->withoutOverlapping()
                ->runInBackground();
        });
    }
}
