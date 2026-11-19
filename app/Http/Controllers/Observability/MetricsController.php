<?php

declare(strict_types=1);

namespace App\Http\Controllers\Observability;

use App\Http\Controllers\Controller;
use App\Platform\Observability\DomainMetricsCollector;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MetricsController extends Controller
{
    public function __construct(
        private readonly DomainMetricsCollector $collector
    ) {}

    /**
     * Exposes Prometheus text exposition metrics (0.0.4) protected by Bearer token.
     */
    public function index(Request $request): Response
    {
        $expectedToken = (string) config('observability.metrics_token', '');

        // Fail-closed: if metrics scrape token is empty/unset in production, deny access
        if ($expectedToken === '') {
            return response("Unauthorized: Scrape token not configured\n", 401, [
                'Content-Type' => 'text/plain; charset=utf-8',
            ]);
        }

        $authHeader = (string) $request->header('Authorization', '');

        if (! str_starts_with($authHeader, 'Bearer ')) {
            return response("Unauthorized: Missing or invalid Bearer token\n", 401, [
                'Content-Type' => 'text/plain; charset=utf-8',
            ]);
        }

        $providedToken = substr($authHeader, 7);

        if (! hash_equals($expectedToken, $providedToken)) {
            return response("Unauthorized: Invalid Bearer token\n", 401, [
                'Content-Type' => 'text/plain; charset=utf-8',
            ]);
        }

        $body = $this->collector->toPrometheusText();

        return response($body, 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
        ]);
    }
}
