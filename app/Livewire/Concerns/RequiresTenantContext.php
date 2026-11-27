<?php

namespace App\Livewire\Concerns;

use App\Core\Context\StoreResolver;
use App\Core\Context\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

trait RequiresTenantContext
{
    protected function allowsSuspendedTenantAccess(): bool
    {
        return false;
    }

    public function bootRequiresTenantContext()
    {
        $user = Auth::user();

        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        if ($user->trashed() || ! $user->is_active || ($user->status ?? 'active') === 'suspended') {
            try {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            } catch (\Throwable) {
            }
            Auth::guard('web')->logout();
            session()->invalidate();
            session()->regenerateToken();
            abort(403, 'Your account is inactive or has been deleted.');
        }

        $tenantId = $user->tenant_id;

        if ($tenantId === null) {
            Auth::guard('web')->logout();
            session()->invalidate();
            session()->regenerateToken();
            abort(403, 'User does not belong to any tenant.');
        }

        $tenant = $user->tenant;
        if (! $tenant || $tenant->trashed()) {
            Auth::guard('web')->logout();
            session()->invalidate();
            session()->regenerateToken();
            abort(403, 'Your account is inactive or has been deleted.');
        }

        // Block operational access when tenant is suspended unless explicitly on a grace recovery component
        if ($tenant->isSuspended()) {
            $refererPath = trim((string) parse_url((string) request()->headers->get('referer', ''), PHP_URL_PATH), '/');
            $isGraceRouteContext = request()->is('subscription*')
                || request()->is('*/subscription*')
                || request()->is('*shifts/*/close*')
                || request()->is('*shifts/close*')
                || request()->is('*kasir/shift/close*')
                || request()->is('*tables/*/release*')
                || request()->is('*orders/*/pay*');

            $isAllowedReferer = $refererPath === ''
                || str_starts_with($refererPath, 'subscription')
                || str_contains($refererPath, '/subscription')
                || str_contains($refererPath, 'shift')
                || str_contains($refererPath, 'transaction')
                || str_contains($refererPath, 'kasir');

            $allowGrace = $this->allowsSuspendedTenantAccess()
                && ($isGraceRouteContext || ((request()->is('livewire*') || app()->runningUnitTests()) && $isAllowedReferer));

            if (! $allowGrace) {
                Auth::guard('web')->logout();
                session()->invalidate();
                session()->regenerateToken();
                abort(403, 'Your business workspace is currently suspended. Please contact customer support.');
            }
        }

        $tenantContext = app(TenantContext::class);
        $tenantContext->setTenantId($tenantId);

        $storeResolver = app(StoreResolver::class);
        $allowed = $storeResolver->allowedStoreIdsFor($user, $tenantId);
        $sessionStoreId = session('active_store_id');
        $isOwner = $user->role?->name === 'Owner' || (bool) ($user->role?->is_admin ?? false);
        $wantsAllStores = $isOwner && ($sessionStoreId === 'all' || $sessionStoreId === null);

        $activeStoreId = $storeResolver->resolve(
            $allowed,
            is_numeric($sessionStoreId) ? (int) $sessionStoreId : null,
        );
        $tenantContext->setStoreId($activeStoreId);
        session(['active_store_id' => $wantsAllStores ? 'all' : $activeStoreId]);
        $tenantContext->setAllowedStoreIds($allowed);
    }

    public function hydrateRequiresTenantContext(): void
    {
        $this->bootRequiresTenantContext();
    }
}
