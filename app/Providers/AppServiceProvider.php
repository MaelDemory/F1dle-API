<?php

namespace App\Providers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\APCng;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CollectorRegistry::class, function () {
            return new CollectorRegistry(new APCng());
        });
    }

    public function boot(): void
    {
        if (!extension_loaded('apcu') || !apcu_enabled()) {
            return;
        }

        $registry = $this->app->make(CollectorRegistry::class);

        DB::listen(function ($query) use ($registry) {
            // Query counter by type
            $type = strtoupper(strtok($query->sql, ' '));
            $counter = $registry->getOrRegisterCounter(
                'f1dle', 'db_queries_total', 'Total database queries',
                ['type']
            );
            $counter->inc([$type]);

            // Query duration histogram
            $histogram = $registry->getOrRegisterHistogram(
                'f1dle', 'db_query_duration_seconds', 'Database query duration in seconds',
                ['type'],
                [0.001, 0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1]
            );
            $histogram->observe($query->time / 1000, [$type]);
        });
    }
}
