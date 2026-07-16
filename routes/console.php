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

Schedule::command('ai-imports:prune')
    ->hourly()
    ->onOneServer()
    ->description('Delete orphaned AI document-import temp files older than 24h');

// Fase 1 do rollout: só relatório + notificação (sem --fix). Ativar --fix
// depois de ~1 semana de relatórios noturnos limpos/compreendidos.
Schedule::command('financial:audit-stale-schedules --notify')
    ->dailyAt('02:00')
    ->onOneServer()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/stale-schedules.log'))
    ->description('Detect payment schedules whose base total diverges from current document values');
