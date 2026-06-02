<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withCommands([
        \App\Domain\Infrastructure\Console\ReconcileBalancesCommand::class,
        \App\Domain\Settings\Console\FetchExchangeRatesCommand::class,
        \App\Domain\Catalog\Console\DeleteDuplicateProductsCommand::class,
        \App\Domain\Catalog\Console\ProductConsolidationPreflightCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
