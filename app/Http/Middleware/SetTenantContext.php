<?php

namespace App\Http\Middleware;

use App\Core\Context\StoreResolver;
use App\Core\Context\TenantContext;
use App\Modules\Organization\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
        if ($user->trashed() || ! $user->is_active || ($user->status ?? 'active') === 'suspended') {
            try {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            } catch (\Throwable) {
            }
            if ($request->hasSession()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
            abort(403, 'Your account is inactive or has been deleted.');
        }

        // 3. Get tenant_id
        $tenantId = $user->tenant_id;

        // 4. If tenant_id is null, deny access
        if ($tenantId === null) {
            if ($request->hasSession()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
            abort(403, 'User does not belong to any tenant.');
        }

        // 5. Validate tenant exists and is not soft-deleted
        $tenant = $user->tenant;
        if (! $tenant || $tenant->trashed()) {
            if ($request->hasSession()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
            abort(403, 'Your account is inactive or has been deleted.'); // Keep messages generic
        }

        // 5b. Block operational access when tenant is suspended (billing, admin, or manual)
        if ($tenant->isSuspended()) {
            if ($request->expectsJson() || $request->is('api/*') || $request->wantsJson()) {
                return response()->json([
                    'error' => 'TENANT_SUSPENDED',
                    'message' => 'Your business workspace is currently suspended. Please contact customer support.',
                ], 403);
            }

            if ($request->hasSession()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
            abort(403, 'Your business workspace is currently suspended. Please contact customer support.');
        }

        // 5c. Block operational access when tenant is purged (EXT-06, UU PDP)
        if ($tenant->isPurged()) {
            if ($request->expectsJson() || $request->is('api/*') || $request->wantsJson()) {
                return response()->json([
                    'error' => 'TENANT_PURGED',
                    'message' => 'Tenant has been permanently purged and anonymized.',
                ], 403);
            }

            if ($request->hasSession()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
            abort(403, 'Tenant has been permanently purged and anonymized.');
        }

        // 5d. Enforce Read-Only mode for ARCHIVED tenants (EXT-06, MULTI_TENANT_ARCHITECTURE_STRATEGY §5)
        if ($tenant->isArchived()) {
            $method = strtoupper($request->method());
            $isSafeMethod = in_array($method, ['GET', 'HEAD', 'OPTIONS'], true);
            $isAllowedMutatingRoute = $request->is('logout') || $request->is('*/logout') || $request->is('*export*');

            if (! $isSafeMethod && ! $isAllowedMutatingRoute) {
                $message = 'Tenant berstatus ARCHIVED (Read-Only). Mutasi data dinonaktifkan.';
                if ($request->expectsJson() || $request->is('api/*') || $request->wantsJson()) {
                    return response()->json([
                        'error' => 'TENANT_ARCHIVED',
                        'message' => $message,
                    ], 403);
                }

                abort(403, $message);
            }
        }

        // 6. Set TenantContext
        $this->tenantContext->setTenantId($tenantId);

        // 6b. Resolve active company under tenant (Tier 2 DEC-001 / Founder Decision 1 & 4)
        $sessionCompanyId = $request->hasSession() ? $request->session()->get('active_company_id') : null;
        $activeCompany = null;
        if ($sessionCompanyId !== null) {
            $activeCompany = Company::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->find($sessionCompanyId);
        }
        if (! $activeCompany) {
            $activeCompany = Company::getDefaultCompanyForTenant($tenantId)
                ?? Company::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('is_active', true)
                    ->orderByDesc('is_default')
                    ->first();
        }
        if ($activeCompany) {
            $this->tenantContext->setCompanyId($activeCompany->id);
            if ($request->hasSession()) {
                $request->session()->put('active_company_id', $activeCompany->id);
            }
        }

        // 7. Resolve active store (cabang). TIDAK PERNAH dari input request.
        $allowed = $this->storeResolver->allowedStoreIdsFor($user, $tenantId);
        $sessionStoreId = $request->hasSession() ? $request->session()->get('active_store_id') : null;
        $isOwner = $user->role?->name === 'Owner' || (bool) ($user->role?->is_admin ?? false);
        $wantsAllStores = $isOwner && ($sessionStoreId === 'all' || $sessionStoreId === null);

        if (empty($allowed)) {
            if ($isOwner && ! $tenant->isOnboardingCompleted()) {
                $this->tenantContext->setStoreId(null);
                if ($request->hasSession()) {
                    $request->session()->put('active_store_id', null);
                }
                $this->tenantContext->setAllowedStoreIds([]);

                return $next($request);
            }

            return response()->view('home.no-access', [], 403);
        }

        $activeStoreId = $this->storeResolver->resolve(
            $allowed,
            is_numeric($sessionStoreId) ? (int) $sessionStoreId : null,
        );

        $this->tenantContext->setStoreId($activeStoreId);
        if ($request->hasSession()) {
            $request->session()->put('active_store_id', $wantsAllStores ? 'all' : $activeStoreId);
        }

        // Himpunan store yang diizinkan disimpan agar lapisan laporan dapat
        // mengecek izin all-stores tanpa query ulang.
        $this->tenantContext->setAllowedStoreIds($allowed);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->tenantContext->clear();
    }
}
