<?php

namespace App\Http\Middleware;

use App\Services\Context\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetTenantContext
{
    public function __construct(private TenantContext $tenantContext)
    {
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // 1. Pastikan request sudah authenticated
        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        // 2. Ambil tenant_id
        $tenantId = $user->tenant_id;

        // 3. Jika tenant_id null, return 403
        if ($tenantId === null) {
            abort(403, 'User does not belong to any tenant.');
        }

        // 4. Validasi tenant eksis dan aktif/non-deleted (kita andalkan relasi untuk memastikan tidak orphaned)
        $tenant = $user->tenant;
        if (! $tenant) {
            abort(403, 'Tenant not found or has been deleted.');
        }

        // 5. Set TenantContext
        $this->tenantContext->setTenantId($tenantId);

        return $next($request);
    }
}
