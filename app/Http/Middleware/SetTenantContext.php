<?php

namespace App\Http\Middleware;

use App\Services\Context\StoreResolver;
use App\Services\Context\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class SetTenantContext
{
    public function __construct(
        private TenantContext $tenantContext,
        private StoreResolver $storeResolver,
    ) {}

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

        // 7. Resolve active store (cabang). TIDAK PERNAH dari input request.
        $allowed = $this->storeResolver->allowedStoreIdsFor($user, $tenantId);
        $sessionStoreId = $request->session()->get('active_store_id');
        $activeStoreId = $this->storeResolver->resolve(
            $allowed,
            is_numeric($sessionStoreId) ? (int) $sessionStoreId : null,
        );

        $this->tenantContext->setStoreId($activeStoreId);
        $request->session()->put('active_store_id', $activeStoreId);

        // Himpunan store yang diizinkan disimpan agar lapisan laporan dapat
        // mengecek izin all-stores tanpa query ulang.
        $this->tenantContext->setAllowedStoreIds($allowed);

        return $next($request);
    }
}
