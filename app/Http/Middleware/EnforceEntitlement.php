<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Context\TenantContext;
use App\Services\EntitlementService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceEntitlement
{
    public function __construct(
        protected EntitlementService $entitlementService
    ) {}

    /**
     * Menegakkan hak akses fitur berbayar (feature gate) berdasarkan paket langganan tenant.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $featureCode): Response
    {
        $tenantId = app(TenantContext::class)->requireTenantId();

        if (! $this->entitlementService->canAccessFeature($tenantId, $featureCode)) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'code' => 'ENTITLEMENT_FORBIDDEN',
                    'message' => "Fitur '{$featureCode}' tidak termasuk dalam paket langganan Anda saat ini. Silakan upgrade paket Anda untuk menggunakan fitur ini.",
                    'feature' => $featureCode,
                ], 403);
            }

            abort(403, "Fitur '{$featureCode}' tidak termasuk dalam paket langganan Anda saat ini. Silakan upgrade paket bisnis Anda.");
        }

        return $next($request);
    }
}
