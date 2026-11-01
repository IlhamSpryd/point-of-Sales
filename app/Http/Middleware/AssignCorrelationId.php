<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Context\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AssignCorrelationId
{
    public function __construct(
        private ?TenantContext $tenantContext = null
    ) {
        $this->tenantContext = $tenantContext ?? (app()->bound(TenantContext::class) ? app(TenantContext::class) : null);
    }

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = $request->header('X-Correlation-ID')
            ?: $request->header('X-Request-ID')
            ?: (string) Str::uuid();

        $request->headers->set('X-Correlation-ID', $correlationId);

        $tenantId = null;
        if ($this->tenantContext && $this->tenantContext->getTenantId() !== null) {
            $tenantId = $this->tenantContext->getTenantId();
        } elseif ($request->user() && isset($request->user()->tenant_id)) {
            $tenantId = $request->user()->tenant_id;
        }

        $logContext = ['correlation_id' => $correlationId];
        if ($tenantId !== null) {
            $logContext['tenant_id'] = $tenantId;
        }

        Log::withContext($logContext);

        $response = $next($request);

        // Update tenant_id in log context if resolved during request pipeline
        if ($tenantId === null && $this->tenantContext && $this->tenantContext->getTenantId() !== null) {
            Log::withContext(['tenant_id' => $this->tenantContext->getTenantId()]);
        }

        $response->headers->set('X-Correlation-ID', $correlationId);

        return $response;
    }
}
