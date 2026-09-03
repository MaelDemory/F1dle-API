<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/metrics', function (CollectorRegistry $registry) {
    // Application status
    $registry->getOrRegisterGauge(
        'f1dle', 'up', 'Whether the application is up'
    )->set(1);

    // PHP info
    $registry->getOrRegisterGauge(
        'f1dle', 'php_info', 'PHP version info', ['version']
    )->set(1, [PHP_VERSION]);

    // Memory usage
    $registry->getOrRegisterGauge(
        'f1dle', 'php_memory_usage_bytes', 'Current PHP memory usage in bytes'
    )->set(memory_get_usage(true));

    $registry->getOrRegisterGauge(
        'f1dle', 'php_memory_peak_bytes', 'Peak PHP memory usage in bytes'
    )->set(memory_get_peak_usage(true));

    // Database row counts
    try {
        $tables = [
            'drivers'            => 'drivers_total',
            'historical_drivers' => 'historical_drivers_total',
            'season_races'       => 'season_races_total',
            'teams'              => 'teams_total',
        ];

        foreach ($tables as $table => $metricName) {
            $count = DB::table($table)->count();
            $registry->getOrRegisterGauge(
                'f1dle', $metricName, "Total rows in {$table} table"
            )->set($count);
        }

        // Database connection pool
        $threads = DB::select('SHOW GLOBAL STATUS WHERE Variable_name IN (?, ?, ?)', [
            'Threads_connected', 'Threads_running', 'Uptime',
        ]);

        foreach ($threads as $row) {
            $name = strtolower($row->Variable_name);
            $registry->getOrRegisterGauge(
                'f1dle', "db_{$name}", "MySQL {$row->Variable_name}"
            )->set((float) $row->Value);
        }

        // Slow queries
        $slowQueries = DB::select("SHOW GLOBAL STATUS WHERE Variable_name = 'Slow_queries'");
        if (!empty($slowQueries)) {
            $registry->getOrRegisterGauge(
                'f1dle', 'db_slow_queries', 'MySQL slow queries count'
            )->set((float) $slowQueries[0]->Value);
        }
    } catch (\Throwable $e) {
        // DB metrics unavailable — skip silently
    }

    $renderer = new RenderTextFormat();
    $result = $renderer->render($registry->getMetricFamilySamples());

    return response($result, 200)
        ->header('Content-Type', RenderTextFormat::MIME_TYPE);
});
