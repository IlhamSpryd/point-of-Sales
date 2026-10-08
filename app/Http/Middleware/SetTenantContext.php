<?php

namespace App\Http\Middleware;

use App\Services\Context\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class SetTenantContext
{
    public function __construct(private TenantContext $tenantContext) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // 1. Ensure request is authenticated
        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        // 2. Validate user is active and not soft-deleted
        // Note: soft-deleted models might be excluded by default global scope,
        // but we explicitly check trashed() if applicable, and is_active.
        if (method_exists($user, 'trashed') && $user->trashed() || ! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            abort(403, 'Your account is inactive or has been deleted.');
        }

        // 3. Get tenant_id
        $tenantId = $user->tenant_id;

        // 4. If tenant_id is null, deny access
        if ($tenantId === null) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            abort(403, 'User does not belong to any tenant.');
        }

        // 5. Validate tenant exists and is not soft-deleted
        $tenant = $user->tenant;
        if (! $tenant || (method_exists($tenant, 'trashed') && $tenant->trashed())) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            abort(403, 'Your account is inactive or has been deleted.'); // Keep messages generic
        }

        // 6. Set TenantContext
        $this->tenantContext->setTenantId($tenantId);

        // Also try to set storeId if user has an active shift or user_store
        // The instructions don't strictly require extracting storeId from user,
        // but it mentions nullable storeId (set/get)
        // Wait, does the user have a default store?
        // Let's just set the TenantId, and the application layer can set StoreId later if needed,
        // or we can set it if there is a known property. I'll leave storeId null initially unless the request has it.

        return $next($request);
    }
}
