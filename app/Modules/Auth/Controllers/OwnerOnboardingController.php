<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Models\OwnerOnboardingToken;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use App\Services\TenantOnboardingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Symfony\Component\HttpFoundation\Response;

/**
 * Domain 1 & Domain 5 — penukaran magic link onboarding Owner pertama (D5).
 *
 * Sifat penting:
 *  - Token dinilai server-side berdasarkan SHA-256 hash; plaintext TIDAK tersimpan.
 *  - GET strictly read-only: email link scanner/prefetcher TIDAK mengonsumsi token.
 *  - Header Referrer-Policy: no-referrer & Cache-Control: no-store mencegah kebocoran token.
 *  - Konsumsi token ATOMIK dengan SELECT ... FOR UPDATE di DB::transaction().
 *  - Token TERIKAT ke (user_id, tenant_id).
 *  - Password ditetapkan via POST HTTPS + validasi Rules\Password.
 *  - Verifikasi email (email_verified_at) di-set pada penukaran yang SUKSES.
 *  - Transisi tenants.onboarding_status ke 'onboarding_business_profile'.
 *  - Rate limit pada route (throttle:6,1).
 */
class OwnerOnboardingController extends Controller
{
    public function show(string $token): Response
    {
        $record = $this->findValidToken($token);

        if ($record === null) {
            return redirect()->route('login')
                ->with('error', 'This activation link is invalid or has expired.');
        }

        $response = response()->view('auth.onboarding-set-password', [
            'token' => $token,
            'tenantName' => $record->tenant->name ?? '',
            'ownerEmail' => $record->user->email ?? '',
        ]);

        // Security headers: cegah kebocoran token via Referer header dan browser cache
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');

        return $response;
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $request->validate([
            'password' => [
                'required',
                'confirmed',
                Rules\Password::min(10)->letters()->mixedCase()->numbers()->uncompromised(),
            ],
        ]);

        $record = $this->findValidToken($token);

        if ($record === null) {
            return redirect()->route('login')
                ->with('error', 'This activation link is invalid or has expired.');
        }

        $result = DB::transaction(function () use ($record, $request) {
            // Lock token row FOR UPDATE untuk mencegah race condition double-submit
            $lockedToken = OwnerOnboardingToken::where('id', $record->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedToken || ! $lockedToken->isRedeemable()) {
                return 'consumed';
            }

            if (! $lockedToken->consume()) {
                return 'consumed';
            }

            $user = User::withTrashed()->find($lockedToken->user_id);

            // Guard lintas-tenant: token yang dibuat untuk user tenant A tidak
            // boleh mengubah user tenant B.
            if ($user === null || $user->trashed()) {
                return 'invalid';
            }

            if ((int) $user->tenant_id !== (int) $lockedToken->tenant_id) {
                return 'cross_tenant';
            }

            // Akun yang sudah punya password bukan lagi akun "onboarding";
            // token tidak boleh menimpanya.
            if (! empty($user->password_hash)) {
                return 'already_activated';
            }

            $user->forceFill([
                'password_hash' => Hash::make($request->string('password')),
                'email_verified_at' => now(), // Verifikasi email sekaligus aktivasi
                'is_active' => true,
                'failed_login_attempts' => 0,
                'locked_until' => null,
            ])->save();

            // Invalidate sibling tokens untuk user ini
            OwnerOnboardingToken::where('tenant_id', $lockedToken->tenant_id)
                ->where('user_id', $lockedToken->user_id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            // Transisi status onboarding tenant ke STEP_1_BUSINESS_PROFILE
            $tenant = Tenant::withoutGlobalScopes()->find($lockedToken->tenant_id);
            if ($tenant && in_array($tenant->onboarding_status, [Tenant::ONBOARDING_PENDING_VERIFICATION, Tenant::ONBOARDING_PENDING, null, ''], true)) {
                $tenant->update(['onboarding_status' => Tenant::ONBOARDING_BUSINESS_PROFILE]);
            }

            return ['status' => 'ok', 'user' => $user];
        });

        if (is_array($result)) {
            $user = $result['user'];

            // Dukung auto-login & direct redirect ke Onboarding Wizard
            $redirectToWizard = config('pos.onboarding_wizard_redirect', false) || $request->boolean('wizard');

            if ($redirectToWizard) {
                Auth::login($user);
                $request->session()->regenerate();
                app(TenantContext::class)->setTenantId($user->tenant_id);

                return redirect()->route('onboarding.business-profile')
                    ->with('success', 'Your password has been set. Let\'s configure your business profile.');
            }

            return redirect()->route('login')
                ->with('success', 'Your password has been set successfully. Please sign in.');
        }

        return match ($result) {
            'already_activated' => redirect()->route('login')
                ->with('error', 'This account has already been activated. Please sign in or reset your password.'),
            default => redirect()->route('login')
                ->with('error', 'This activation link is invalid or has expired.'),
        };
    }

    /**
     * Kirim ulang token aktivasi (self-service resend).
     * Terlindungi rate limit dan enumeration-safe.
     */
    public function resend(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
        ]);

        app(TenantOnboardingService::class)->resendOnboardingToken($request->string('email'));

        return redirect()->back()
            ->with('success', 'If your email address is registered and pending activation, a new activation link has been sent.');
    }

    /**
     * Cari token valid: hash cocok, belum dipakai, belum kedaluwarsa, user
     * masih aktif dan masih berada di tenant yang sama dengannya.
     */
    private function findValidToken(string $plaintext): ?OwnerOnboardingToken
    {
        if ($plaintext === '' || strlen($plaintext) > 255) {
            return null;
        }

        $record = OwnerOnboardingToken::findRedeemable($plaintext);

        if ($record === null || ! $record->isRedeemable()) {
            return null;
        }

        $user = User::find($record->user_id);

        if ($user === null || ! $user->is_active) {
            return null;
        }

        // Token TERIKAT tenant: user yang sudah berpindah tenant tidak boleh
        // memakai token lamanya.
        if ((int) $user->tenant_id !== (int) $record->tenant_id) {
            return null;
        }

        return $record;
    }
}
