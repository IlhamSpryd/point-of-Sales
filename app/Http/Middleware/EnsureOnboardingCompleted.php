<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOnboardingCompleted
{
    /**
     * Pastikan Owner menyelesaikan Wizard Onboarding sebelum mengakses modul operasional POS.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('pos.enforce_onboarding_wizard', true)) {
            return $next($request);
        }

        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $isOwner = $user->role?->name === 'Owner' || (bool) ($user->role->is_admin ?? false);

        // Hanya Owner yang wajib menyelesaikan onboarding wizard bisnis
        if (! $isOwner) {
            return $next($request);
        }

        $tenant = $user->tenant;
        if ($tenant && ! $tenant->isOnboardingCompleted()) {
            $tenant = $tenant->fresh() ?? $tenant;
        }

        if ($tenant === null || $tenant->isOnboardingCompleted()) {
            return $next($request);
        }

        // Rute-rute yang dikecualikan agar Owner dapat menyelesaikan onboarding atau logout
        $allowedRoutes = [
            'onboarding.business-profile',
            'onboarding.business-profile.store',
            'onboarding.initial-branch',
            'onboarding.initial-branch.store',
            'onboarding.set-password',
            'onboarding.set-password.store',
            'onboarding.resend',
            'verification.notice',
            'verification.verify',
            'verification.send',
            'verification.otp',
            'logout',
        ];

        if ($request->routeIs($allowedRoutes)) {
            return $next($request);
        }

        // Arahkan ke step onboarding yang sesuai dengan progress saat ini
        if ($tenant->onboarding_status === Tenant::ONBOARDING_INITIAL_BRANCH) {
            return redirect()->route('onboarding.initial-branch')
                ->with('info', 'Silakan lengkapi pengaturan cabang pertama Anda sebelum melanjutkan.');
        }

        return redirect()->route('onboarding.business-profile')
            ->with('info', 'Silakan lengkapi profil bisnis Anda terlebih dahulu.');
    }
}
