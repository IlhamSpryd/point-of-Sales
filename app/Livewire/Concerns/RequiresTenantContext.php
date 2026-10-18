<?php

namespace App\Livewire\Concerns;

use App\Services\Context\StoreResolver;
use App\Services\Context\TenantContext;
use Illuminate\Support\Facades\Auth;

trait RequiresTenantContext
{
    public function bootRequiresTenantContext()
    {
        $user = Auth::user();

        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        if ((method_exists($user, 'trashed') && $user->trashed()) || ! $user->is_active || ($user->status ?? 'active') === 'suspended') {
            try {
                \Illuminate\Support\Facades\DB::table('sessions')->where('user_id', $user->id)->delete();
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
