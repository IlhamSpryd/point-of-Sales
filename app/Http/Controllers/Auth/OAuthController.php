<?php

namespace App\Http\Controllers\Auth;

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
                ->with('error', 'Silakan masuk terlebih dahulu untuk menautkan akun.');
        }

        $url = $this->oauthService->getRedirectUrl(
            $provider,
            $intent,
            Auth::id(),
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
                ->with('error', 'Parameter state tidak ditemukan.');
        }

        $cachedState = $this->oauthService->pullState($state);
        if ($cachedState === null) {
            return redirect()->route('login')
                ->with('error', 'Sesi autentikasi telah kedaluwarsa atau state tidak valid.');
        }

        // 2. Periksa error dari provider
        if ($request->has('error')) {
            $err = $request->input('error_description', $request->input('error'));

            return redirect()->route('login')
                ->with('error', "Autentikasi ditolak oleh provider: {$err}");
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
                    ->with('error', 'Gagal menukarkan authorization code dengan provider.');
            }
        }

        if (empty($idToken)) {
            return redirect()->route('login')
                ->with('error', 'ID Token tidak ditemukan dari respons provider.');
        }

        // 4. Verifikasi ID Token RS256, Nonce, Issuer, Audience, Expiration
        try {
            $claims = $this->oauthService->verifyIdToken($provider, $idToken, $cachedState['nonce']);
        } catch (\Throwable $e) {
            return redirect()->route('login')
                ->with('error', 'Verifikasi token OIDC gagal: '.$e->getMessage());
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
     */
    protected function handleAcceptInvitation(string $provider, array $claims, array $cachedState, Request $request): Response
    {
        $invitationToken = $cachedState['invitation_token'] ?? null;
        if (! $invitationToken) {
            return redirect()->route('login')
                ->with('error', 'Token undangan tidak ditemukan.');
        }

        try {
            $invitationService = app(UserInvitationService::class);
            $user = $invitationService->acceptInvitationViaOAuth($invitationToken, $provider, $claims);

            Auth::login($user);
            $request->session()->regenerate();
            app(TenantContext::class)->setTenantId($user->tenant_id);

            return redirect()->route('dashboard')
                ->with('success', 'Selamat datang! Akun karyawan Anda telah aktif.');
        } catch (HttpException $e) {
            return redirect()->route('login')
                ->with('error', $e->getMessage() ?: 'Gagal memproses aktivasi undangan via '.ucfirst($provider).'.');
        } catch (\Throwable $e) {
            return redirect()->route('login')
                ->with('error', 'Gagal memproses aktivasi undangan: '.$e->getMessage());
        }
    }

    /**
     * Alur Login Standar (/login) — Menegakkan Zero Auto-Provisioning & Zero Auto-Linking.
     */
    protected function handleLogin(string $provider, array $claims, Request $request): Response
    {
        $subject = $claims['sub'];
        $identity = UserOAuthIdentity::where('provider', $provider)
            ->where('provider_subject', $subject)
            ->first();

        // KASUS 1: Akun sosial SUDAH tertaut ke User
        if ($identity !== null) {
            $user = $identity->user;

            if ($user === null || $user->trashed() || ($user->status ?? 'active') === 'suspended' || ! $user->is_active) {
                return redirect()->route('login')
                    ->with('error', 'Akun Anda tidak aktif atau telah ditangguhkan.');
            }

            $identity->update(['last_used_at' => now()]);

            Auth::login($user);
            $request->session()->regenerate();
            app(TenantContext::class)->setTenantId($user->tenant_id);

            $tenant = $user->tenant;
            if ($tenant && ! $tenant->isOnboardingCompleted()) {
                return redirect()->route('onboarding.business-profile');
            }

            return redirect()->intended(route('dashboard', absolute: false));
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
                description: "Percobaan login {$provider} ditolak karena belum ditautkan ke akun eksisting.",
                ipAddress: $request->ip(),
                userAgent: $request->userAgent()
            );

            return redirect()->route('login')
                ->with('error', 'Akun '.ucfirst($provider).' ini belum ditautkan. Silakan masuk menggunakan email dan password Anda, lalu tautkan akun '.ucfirst($provider).' melalui menu Pengaturan Profil.');
        }

        // KASUS 3: Belum tertaut dan email belum ada (Zero Auto-Provisioning Guard [REQ-SEC-20])
        return redirect()->route('login')
            ->with('error', 'Akun Anda belum terdaftar. Silakan daftar sebagai Owner bisnis baru atau hubungi administrator untuk mendapatkan undangan staf.');
    }

    /**
     * Alur Registrasi Owner Baru dari /register via Google/Apple OIDC.
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
                ->with('error', "Email {$claims['email']} sudah terdaftar. Silakan masuk menggunakan password Anda dan tautkan akun ".ucfirst($provider).' di profil.');
        }

        $lockKey = 'lock:identity:email:'.hash('sha256', $claims['email_normalized']);
        $lock = Cache::lock($lockKey, 10);

        if (! $lock->get()) {
            return redirect()->route('login')
                ->with('status', 'Pendaftaran sedang diproses.');
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
     */
    protected function handleLinkAccount(string $provider, array $claims, array $cachedState): Response
    {
        $currentUserId = Auth::id();
        $initiatorUserId = (int) ($cachedState['initiator_user_id'] ?? 0);

        if (! $currentUserId || $currentUserId !== $initiatorUserId) {
            return redirect()->route('login')
                ->with('error', 'Sesi penautan tidak sah. Silakan login terlebih dahulu.');
        }

        $user = Auth::user();

        // 1. Pastikan provider_subject ini belum ditautkan ke akun mana pun
        $existingLink = UserOAuthIdentity::where('provider', $provider)
            ->where('provider_subject', $claims['sub'])
            ->first();

        if ($existingLink !== null) {
            return redirect()->route('profile.edit')
                ->with('error', 'Akun '.ucfirst($provider).' ini sudah ditautkan ke akun lain.');
        }

        // 2. Pastikan user belum menautkan provider yang sama
        if ($user->oauthIdentities()->where('provider', $provider)->exists()) {
            return redirect()->route('profile.edit')
                ->with('error', 'Anda sudah menautkan akun '.ucfirst($provider).'.');
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
            'subject' => 'Akun '.ucfirst($provider).' Berhasil Ditautkan — Apeiron POS',
            'body_text' => "Halo {$user->name},\n\nAkun ".ucfirst($provider)." ({$claims['email']}) berhasil ditautkan ke akun Apeiron POS Anda.\n\nJika Anda tidak melakukan penautan ini, segera amankan akun Anda atau hubungi dukungan sistem.\n\nSalam,\nTim Apeiron POS",
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'status' => EmailOutbox::STATUS_PENDING,
            'attempts' => 0,
        ]);

        return redirect()->route('profile.edit')
            ->with('success', 'Akun '.ucfirst($provider).' berhasil ditautkan.');
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
            app(\App\Services\AuditTrailService::class)->log(
                tenantId: (int) $user->tenant_id,
                action: 'oauth.unlink_blocked',
                userId: $user->id,
                description: "Percobaan pelepasan akun OAuth {$provider} diblokir oleh anti-lockout guard (tidak ada kata sandi dan merupakan metode login terakhir).",
            );

            return back()->with('error', 'Tidak dapat melepas tautan akun ini karena merupakan satu-satunya metode login Anda. Silakan tentukan kata sandi terlebih dahulu.');
        }

        $identity = $user->oauthIdentities()->where('provider', $provider)->first();
        if ($identity !== null) {
            $identity->delete();

            app(\App\Services\AuditTrailService::class)->log(
                tenantId: (int) $user->tenant_id,
                action: 'oauth.unlinked',
                userId: $user->id,
                description: 'Tautan akun '.ucfirst($provider).' berhasil dilepas.',
            );
        }

        return back()->with('success', 'Tautan akun '.ucfirst($provider).' berhasil dilepas.');
    }
}
