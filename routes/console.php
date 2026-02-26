<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('exchange-rates:fetch --auto-approve')
    ->dailyAt('17:00')
    ->timezone('CET')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/exchange-rates.log'))
    ->description('Fetch daily exchange rates from Frankfurter API (ECB publishes ~16:00 CET)');
