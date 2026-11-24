<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Context\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-Side Vertical Middleware Guard (ADR-118).
 * Enforces fail-closed access control on vertical-exclusive routes.
 * A tenant or store marked as 'retail' cannot access 'fnb' routes (/kds, /tables),
 * and an 'fnb' outlet cannot access 'retail' routes (/retail/shelf-labels, /retail/exchanges).
 * 'hybrid' stores/tenants have access to both verticals.
 */
class EnsureVerticalCapability
{
    public function handle(Request $request, Closure $next, string $requiredVertical): Response
    {
        $tenantContext = app(TenantContext::class);
        $activeVertical = 'hybrid';

        $storeId = $tenantContext->getStoreId();
        if ($storeId !== null) {
            $storeRow = DB::table('stores')
                ->where('id', $storeId)
                ->first(['store_vertical', 'tenant_id']);

            if ($storeRow !== null && ! empty($storeRow->store_vertical)) {
                $activeVertical = $storeRow->store_vertical;
            } elseif ($storeRow !== null && $storeRow->tenant_id) {
                $tenantIndustry = DB::table('tenants')
                    ->where('id', $storeRow->tenant_id)
                    ->value('primary_industry');
                if (! empty($tenantIndustry)) {
                    $activeVertical = $tenantIndustry;
                }
            }
        } elseif ($tenantContext->getTenantId() !== null) {
            $tenantIndustry = DB::table('tenants')
                ->where('id', $tenantContext->getTenantId())
                ->value('primary_industry');
            if (! empty($tenantIndustry)) {
                $activeVertical = $tenantIndustry;
            }
        }

        // Hybrid has access to both verticals
        if ($activeVertical === 'hybrid') {
            return $next($request);
        }

        if ($activeVertical !== $requiredVertical) {
            abort(403, "Access denied: This module requires {$requiredVertical} vertical capability.");
        }

        return $next($request);
    }
}
