<?php

namespace App\Modules\Auth\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\EmailOutbox;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserOAuthIdentity;
use App\Services\AuditTrailService;
use App\Services\Context\TenantContext;
use App\Services\OAuthService;
use App\Services\UserInvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OAuthController extends Controller
{
    public function __construct(
        protected OAuthService $oauthService
    ) {}

    /**
     * Memulai alur otorisasi OIDC ke Google atau Apple.
     */
    public function redirect(Request $request, string $provider): RedirectResponse
    {
        $intent = $request->query('intent', OAuthService::INTENT_LOGIN);

        if ($intent === OAuthService::INTENT_LINK_ACCOUNT && ! Auth::check()) {
            return redirect()->route('login')
                ->with('error', 'Please sign in first to link your account.');
        }

        $authId = Auth::id();
        $url = $this->oauthService->getRedirectUrl(
            $provider,
            $intent,
            $authId !== null ? (int) $authId : null,
            $request->query('invitation_token'),
            $request
        );

        return redirect()->away($url);
    }

    /**
     * Callback GET dari Google OIDC.
     */
    public function googleCallback(Request $request): Response
    {
        return $this->handleCallback(OAuthService::PROVIDER_GOOGLE, $request);
    }

    /**
     * Callback POST (form_post) dari Sign in with Apple.
     */
    public function appleCallback(Request $request): Response
    {
        return $this->handleCallback(OAuthService::PROVIDER_APPLE, $request);
    }

    /**
     * Pemrosesan terpadu callback OIDC Google & Apple.
     */
    protected function handleCallback(string $provider, Request $request): Response
    {
        // 1. Ekstraksi dan konsumsi state secara atomik (single-use anti-replay)
        $state = (string) $request->input('state');
        if (empty($state)) {
            return redirect()->route('login')
                ->with('error', 'State parameter not found.');
        }

        $cachedState = $this->oauthService->pullState($state);
        if ($cachedState === null) {
            return redirect()->route('login')
                ->with('error', 'Authentication session expired or invalid state.');
        }

        // 2. Periksa error dari provider
        if ($request->has('error')) {
            $err = $request->input('error_description', $request->input('error'));

            return redirect()->route('login')
                ->with('error', "Authentication rejected by provider: {$err}");
        }

        // 3. Dapatkan ID Token
        $code = (string) $request->input('code');
        $idToken = (string) ($request->input('id_token') ?? '');

        if (empty($idToken) && ! empty($code)) {
            try {
                $idToken = $this->oauthService->exchangeCodeForIdToken(
                    $provider,
                    $code,
                    $cachedState['code_verifier'] ?? null
                );
            } catch (\Throwable $e) {
                return redirect()->route('login')
                    ->with('error', 'Failed to exchange authorization code with provider.');
            }
        }

        if (empty($idToken)) {
            return redirect()->route('login')
                ->with('error', 'ID token not found in provider response.');
        }

        // 4. Verifikasi ID Token RS256, Nonce, Issuer, Audience, Expiration
        try {
            $claims = $this->oauthService->verifyIdToken($provider, $idToken, $cachedState['nonce']);
        } catch (\Throwable $e) {
            return redirect()->route('login')
                ->with('error', 'OIDC token verification failed: '.$e->getMessage());
        }

        // Jika Apple mengirimkan payload 'user' JSON pada login pertama
        if ($provider === OAuthService::PROVIDER_APPLE && $request->has('user')) {
            $userPayload = json_decode((string) $request->input('user'), true);
            if (is_array($userPayload) && ! empty($userPayload['name'])) {
                $first = $userPayload['name']['firstName'] ?? '';
                $last = $userPayload['name']['lastName'] ?? '';
                $fullName = trim("{$first} {$last}");
                if ($fullName !== '') {
                    $claims['name'] = $fullName;
                }
            }
        }

        $intent = $cachedState['intent'] ?? OAuthService::INTENT_LOGIN;

        return match ($intent) {
            OAuthService::INTENT_LINK_ACCOUNT => $this->handleLinkAccount($provider, $claims, $cachedState),
            OAuthService::INTENT_REGISTER_OWNER => $this->handleRegisterOwner($provider, $claims, $request),
            OAuthService::INTENT_ACCEPT_INVITATION => $this->handleAcceptInvitation($provider, $claims, $cachedState, $request),
            default => $this->handleLogin($provider, $claims, $request),
        };
    }

    /**
     * Alur Aktivasi Undangan Karyawan via Google/Apple OIDC.
     *
     * @param  array<string, mixed>  $cachedState
     * @param  array<string, mixed>  $claims
     */
    protected function handleAcceptInvitation(string $provider, array $claims, array $cachedState, Request $request): Response
    {
        $invitationToken = $cachedState['invitation_token'] ?? null;
        if (! $invitationToken) {
            return redirect()->route('login')
                ->with('error', 'Invitation token not found.');
        }

        try {
            $invitationService = app(UserInvitationService::class);
            $user = $invitationService->acceptInvitationViaOAuth($invitationToken, $provider, $claims);

            Auth::login($user);
            $request->session()->regenerate();
            app(TenantContext::class)->setTenantId((int) $user->tenant_id);

            return redirect()->route('home')
                ->with('success', 'Welcome! Your employee account is now active.');
        } catch (HttpException $e) {
            return redirect()->route('login')
                ->with('error', $e->getMessage() ?: 'Failed to activate invitation via '.ucfirst($provider).'.');
        } catch (\Throwable $e) {
            return redirect()->route('login')
                ->with('error', 'Failed to process invitation: '.$e->getMessage());
        }
    }

    /**
     * Alur Login Standar (/login) — Menegakkan Zero Auto-Provisioning & Zero Auto-Linking.
     *
     * @param  array<string, mixed>  $claims
     */
    protected function handleLogin(string $provider, array $claims, Request $request): Response
    {
        $subject = $claims['sub'];
        $identity = UserOAuthIdentity::where('provider', $provider)
            ->where('provider_subject', $subject)
            ->first();

        // KASUS 1: Akun sosial SUDAH tertaut ke User
        if ($identity !== null) {
            $user = User::withoutGlobalScopes()
                ->withTrashed()
                ->where('tenant_id', $identity->tenant_id)
                ->where('id', $identity->user_id)
                ->first();

            if ($user === null || $user->trashed() || ($user->status ?? 'active') === 'suspended' || ! $user->is_active) {
                return redirect()->route('login')
                    ->with('error', 'Your account is inactive or has been suspended.');
            }

            $identity->update(['last_used_at' => now()]);

            Auth::login($user);
            $request->session()->regenerate();
            app(TenantContext::class)->setTenantId((int) $user->tenant_id);

            $tenant = $user->tenant;
            if ($tenant && ! $tenant->isOnboardingCompleted()) {
                return redirect()->route('onboarding.business-profile');
            }

            return redirect()->intended(route('home', absolute: false));
        }

        // KASUS 2: Belum tertaut, tetapi email sudah ada di tabel users (Zero Auto-Linking Guard [REQ-SEC-21])
        $existingUser = User::withoutGlobalScopes()
            ->where('email_normalized', $claims['email_normalized'])
            ->whereNull('deleted_at')
            ->first();

        if ($existingUser !== null) {
            app(AuditTrailService::class)->log(
                tenantId: (int) $existingUser->tenant_id,
                action: 'oauth_auto_link_blocked',
                userId: (int) $existingUser->id,
                description: "Login attempt via {$provider} rejected because it has not been linked to an existing account.",
                ipAddress: $request->ip(),
                userAgent: $request->userAgent()
            );

            return redirect()->route('login')
                ->with('error', 'This '.ucfirst($provider).' account is not linked. Please sign in with your email and password, then link your '.ucfirst($provider).' account in Profile Settings.');
        }

        // KASUS 3: Belum tertaut dan email belum ada (Zero Auto-Provisioning Guard [REQ-SEC-20])
        return redirect()->route('login')
            ->with('error', 'Your account is not registered. Please register as a new business owner or contact your administrator for a staff invitation.');
    }

    /**
     * Alur Registrasi Owner Baru dari /register via Google/Apple OIDC.
     *
     * @param  array<string, mixed>  $claims
     */
    protected function handleRegisterOwner(string $provider, array $claims, Request $request): Response
    {
        $subject = $claims['sub'];

        // Jika sub sudah pernah didaftarkan, langsung loginkan
        $identity = UserOAuthIdentity::where('provider', $provider)
            ->where('provider_subject', $subject)
            ->first();

        if ($identity !== null) {
            return $this->handleLogin($provider, $claims, $request);
        }

        // Jika email sudah dipakai akun lain, tolak auto-link
        $existingUser = User::withoutGlobalScopes()
            ->where('email_normalized', $claims['email_normalized'])
            ->whereNull('deleted_at')
            ->first();

        if ($existingUser !== null) {
            return redirect()->route('login')
                ->with('error', "Email {$claims['email']} is already registered. Please sign in using your password and link your ".ucfirst($provider).' account in your profile.');
        }

        $lockKey = 'lock:identity:email:'.hash('sha256', $claims['email_normalized']);
        $lock = Cache::lock($lockKey, 10);

        if (! $lock->get()) {
            return redirect()->route('login')
                ->with('status', 'Registration is currently processing.');
        }

        try {
            return DB::transaction(function () use ($provider, $claims, $request) {
                $ownerName = $claims['name'] ?: 'Owner';
                $businessName = $ownerName."'s Business";

                $tenant = Tenant::create([
                    'name' => $businessName,
                    'slug' => Str::slug($businessName).'-'.Str::lower(Str::random(6)),
                    'status' => 'pending',
                    'onboarding_status' => Tenant::ONBOARDING_PENDING_VERIFICATION,
                    'access_status' => Tenant::ACCESS_PENDING,
                    'subscription_status' => Tenant::SUBSCRIPTION_TRIAL,
                    'subscription_plan' => 'standard_trial',
                    'country_code' => 'ID',
                ]);

                app(TenantContext::class)->setTenantId($tenant->id);

                $ownerRole = new Role;
                $ownerRole->tenant_id = $tenant->id;
                $ownerRole->name = 'Owner';
                $ownerRole->description = 'Tenant Owner';
                $ownerRole->permissions = ['*'];
                $ownerRole->is_active = true;
                $ownerRole->save();

                $user = new User;
                $user->tenant_id = $tenant->id;
                $user->role_id = $ownerRole->id;
                $user->name = $ownerName;
                $user->email = $claims['email'];
                $user->email_normalized = $claims['email_normalized'];
                $user->password_hash = null; // Akun murni OAuth
                $user->is_active = true;
                $user->email_verified_at = $claims['email_verified'] ? now() : null;
                $user->failed_login_attempts = 0;
                $user->save();

                UserOAuthIdentity::create([
                    'tenant_id' => $tenant->id,
                    'user_id' => $user->id,
                    'provider' => $provider,
                    'provider_subject' => $claims['sub'],
                    'provider_email' => $claims['email'],
                    'provider_email_normalized' => $claims['email_normalized'],
                    'provider_email_verified' => $claims['email_verified'],
                    'is_private_relay' => $claims['is_private_relay'],
                    'linked_at' => now(),
                    'last_used_at' => now(),
                ]);

                Auth::login($user);
                $request->session()->regenerate();
                app(TenantContext::class)->setTenantId($tenant->id);

                if (! $claims['email_verified']) {
                    return redirect()->route('verification.notice');
                }

                return redirect()->route('onboarding.business-profile');
            });
        } finally {
            $lock->release();
        }
    }

    /**
     * Alur Penautan Akun dari Halaman Profil (/profile).
     *
     * @param  array<string, mixed>  $cachedState
     * @param  array<string, mixed>  $claims
     */
    protected function handleLinkAccount(string $provider, array $claims, array $cachedState): Response
    {
        $currentUserId = Auth::id();
        $initiatorUserId = (int) ($cachedState['initiator_user_id'] ?? 0);

        if (! $currentUserId || $currentUserId !== $initiatorUserId) {
            return redirect()->route('login')
                ->with('error', 'Unauthorized linking session. Please sign in first.');
        }

        $user = Auth::user();
        abort_unless($user !== null, 401);

        // 1. Pastikan provider_subject ini belum ditautkan ke akun mana pun
        $existingLink = UserOAuthIdentity::where('provider', $provider)
            ->where('provider_subject', $claims['sub'])
            ->first();

        if ($existingLink !== null) {
            return redirect()->route('profile.edit')
                ->with('error', 'This '.ucfirst($provider).' account is already linked to another user.');
        }

        // 2. Pastikan user belum menautkan provider yang sama
        if ($user->oauthIdentities()->where('provider', $provider)->exists()) {
            return redirect()->route('profile.edit')
                ->with('error', 'You have already linked your '.ucfirst($provider).' account.');
        }

        // 3. Tautkan identitas
        UserOAuthIdentity::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'provider' => $provider,
            'provider_subject' => $claims['sub'],
            'provider_email' => $claims['email'],
            'provider_email_normalized' => $claims['email_normalized'],
            'provider_email_verified' => $claims['email_verified'],
            'is_private_relay' => $claims['is_private_relay'],
            'linked_at' => now(),
            'last_used_at' => now(),
        ]);

        // 4. Kirim notifikasi keamanan via EmailOutbox
        EmailOutbox::create([
            'event_type' => 'oauth_account_linked',
            'recipient_email' => $user->email,
            'subject' => ucfirst($provider).' Account Linked Successfully — Apeiron POS',
            'body_text' => "Hello {$user->name},\n\nYour ".ucfirst($provider)." account ({$claims['email']}) has been successfully linked to your Apeiron POS account.\n\nIf you did not perform this action, please secure your account immediately or contact support.\n\nRegards,\nApeiron POS Team",
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'status' => EmailOutbox::STATUS_PENDING,
            'attempts' => 0,
        ]);

        return redirect()->route('profile.edit')
            ->with('success', 'Your '.ucfirst($provider).' account has been linked successfully.');
    }

    /**
     * Pelepasan kaitan OAuth dari Profil (DELETE /profile/oauth/{provider}) dengan Anti-Lockout.
     */
    public function unlink(Request $request, string $provider): RedirectResponse
    {
        $user = $request->user();
        if ($user === null) {
            return redirect()->route('login');
        }

        // Anti-lockout guard:
        // Wajib memiliki minimal satu metode login lain (password_hash/password ATAU provider OAuth lain)
        $hasPassword = ! empty($user->password_hash) || ! empty($user->password);
        $otherProvidersCount = $user->oauthIdentities()
            ->where('provider', '!=', $provider)
            ->count();

        if (! $hasPassword && $otherProvidersCount === 0) {
            app(AuditTrailService::class)->log(
                tenantId: (int) $user->tenant_id,
                action: 'oauth.unlink_blocked',
                userId: $user->id,
                description: "Percobaan pelepasan akun OAuth {$provider} diblokir oleh anti-lockout guard (tidak ada kata sandi dan merupakan metode login terakhir).",
            );

            return back()->with('error', 'Cannot unlink this account as it is your only login method. Please set a password first.');
        }

        $identity = $user->oauthIdentities()->where('provider', $provider)->first();
        if ($identity !== null) {
            $identity->delete();

            app(AuditTrailService::class)->log(
                tenantId: (int) $user->tenant_id,
                action: 'oauth.unlinked',
                userId: $user->id,
                description: 'Tautan akun '.ucfirst($provider).' berhasil dilepas.',
            );
        }

        return back()->with('success', 'Your '.ucfirst($provider).' account has been unlinked successfully.');
    }
}
