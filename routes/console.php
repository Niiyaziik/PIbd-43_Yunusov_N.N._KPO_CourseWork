<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Ежедневное обновление CSV для тикера BANE
Schedule::command('securities:update-csv --ticker=BANE')
    ->dailyAt('02:00'); // время можно изменить при необходимости
