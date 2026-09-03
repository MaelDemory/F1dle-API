<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Prometheus\CollectorRegistry;
use Symfony\Component\HttpFoundation\Response;

class PrometheusMiddleware
{
    public function __construct(
        private CollectorRegistry $registry,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);

        // Track in-progress requests
        $inProgress = $this->registry->getOrRegisterGauge(
            'f1dle', 'http_requests_in_progress', 'Number of HTTP requests currently being processed',
            ['method']
        );
        $inProgress->inc([$request->method()]);

        /** @var Response $response */
        $response = $next($request);

        $duration = microtime(true) - $start;
        $route = $this->normalizeRoute($request);
        $method = $request->method();
        $status = (string) $response->getStatusCode();

        // Request counter
        $counter = $this->registry->getOrRegisterCounter(
            'f1dle', 'http_requests_total', 'Total HTTP requests',
            ['method', 'route', 'status']
        );
        $counter->inc([$method, $route, $status]);

        // Request duration histogram
        $histogram = $this->registry->getOrRegisterHistogram(
            'f1dle', 'http_request_duration_seconds', 'HTTP request duration in seconds',
            ['method', 'route', 'status'],
            [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10]
        );
        $histogram->observe($duration, [$method, $route, $status]);

        // Response size
        $size = strlen($response->getContent() ?: '');
        $sizeHistogram = $this->registry->getOrRegisterHistogram(
            'f1dle', 'http_response_size_bytes', 'HTTP response size in bytes',
            ['method', 'route'],
            [100, 500, 1000, 5000, 10000, 50000, 100000, 500000]
        );
        $sizeHistogram->observe($size, [$method, $route]);

        $inProgress->dec([$method]);

        return $response;
    }

    private function normalizeRoute(Request $request): string
    {
        $route = $request->route();

        if ($route && method_exists($route, 'uri')) {
            return '/' . ltrim($route->uri(), '/');
        }

        return $request->path();
    }
}
