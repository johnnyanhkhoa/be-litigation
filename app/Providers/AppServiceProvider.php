<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Queue;
use App\Jobs\SendAsteriskLogToLitJob;
use Illuminate\Support\Facades\Log;

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
        // Map queue consumer to job
        Queue::before(function ($event) {
            if ($event->connectionName === 'rabbitmq_asterisk') {
                Log::info('Processing Asterisk message from RabbitMQ', [
                    'job' => $event->job->getName(),
                    'queue' => $event->job->getQueue()
                ]);
            }
        });
    }
}
