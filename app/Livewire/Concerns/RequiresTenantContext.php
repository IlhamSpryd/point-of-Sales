<?php

namespace App\Livewire\Concerns;

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

        if (method_exists($user, 'trashed') && $user->trashed() || ! $user->is_active) {
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
        if (! $tenant || (method_exists($tenant, 'trashed') && $tenant->trashed())) {
            Auth::guard('web')->logout();
            session()->invalidate();
            session()->regenerateToken();
            abort(403, 'Your account is inactive or has been deleted.');
        }

        app(TenantContext::class)->setTenantId($tenantId);
    }
}
