<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Artisan;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

    // Register Asterisk log consumer command
    Artisan::command('queue:work-asterisk', function () {
        $this->info('Starting Asterisk log consumer for Litigation...');

        Artisan::call('queue:work', [
            'connection' => 'rabbitmq_asterisk',
            '--queue' => 'asterisk_log',
            '--tries' => 3,
            '--timeout' => 300,
        ]);
    })->purpose('Start RabbitMQ consumer for Asterisk call logs (Litigation)');
