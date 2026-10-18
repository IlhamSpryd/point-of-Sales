<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditTrailService;
use App\Services\TwoFactorAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class TwoFactorAuthenticationController extends Controller
{
    public function __construct(
        protected TwoFactorAuthService $twoFactorService,
        protected AuditTrailService $auditTrail
    ) {}

    /**
     * Memulai proses pengaktifan 2FA (generate secret & recovery codes awal).
     */
    public function enable(Request $request): RedirectResponse|JsonResponse
    {
        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            return back()->with('info', 'Autentikasi dua faktor sudah aktif pada akun Anda.');
        }

        $secret = $this->twoFactorService->generateSecretKey();
        $recoveryCodes = $this->twoFactorService->generateRecoveryCodes();

        $user->two_factor_secret = $secret;
        $user->two_factor_recovery_codes = $recoveryCodes;
        $user->two_factor_confirmed_at = null; // Belum terkonfirmasi
        $user->save();

        $qrUri = $this->twoFactorService->getQrCodeUri($user, $secret);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'secret' => $secret,
                'qr_uri' => $qrUri,
                'recovery_codes' => $recoveryCodes,
            ]);
        }

        return back()->with([
            'status' => 'two-factor-setup-initiated',
            'two_factor_secret' => $secret,
            'two_factor_qr_uri' => $qrUri,
            'two_factor_recovery_codes' => $recoveryCodes,
        ]);
    }

    /**
     * Mengonfirmasi kode 6-digit pertama untuk menyelesaikan aktivasi 2FA.
     */
    public function confirm(Request $request): RedirectResponse|JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'size:6'],
        ]);

        $user = $request->user();
        $code = (string) $request->input('code');

        if (! $this->twoFactorService->confirmTwoFactor($user, $code)) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Kode autentikasi 6-digit tidak valid atau sudah kedaluwarsa.',
                ], 422);
            }

            return back()->withErrors(['code' => 'Kode autentikasi 6-digit tidak valid atau sudah kedaluwarsa.']);
        }

        $request->session()->put('two_factor_confirmed_at', now()->timestamp);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Autentikasi dua faktor berhasil diaktifkan.',
            ]);
        }

        return back()->with('success', 'Autentikasi dua faktor (2FA) berhasil diaktifkan pada akun Anda.');
    }

    /**
     * Menonaktifkan 2FA dari akun pengguna (memerlukan kata sandi).
     */
    public function disable(Request $request): RedirectResponse|JsonResponse
    {
        $user = $request->user();

        // Jika user memiliki kata sandi, minta konfirmasi kata sandi
        if (! empty($user->password_hash) || ! empty($user->password)) {
            $request->validate([
                'password' => ['required', 'string'],
            ]);

            $currentHash = $user->password_hash ?? $user->password;
            if (! Hash::check($request->input('password'), $currentHash)) {
                if ($request->wantsJson()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Kata sandi saat ini salah.',
                    ], 422);
                }

                return back()->withErrors(['password' => 'Kata sandi saat ini salah.']);
            }
        }

        $this->twoFactorService->disableTwoFactor($user);
        $request->session()->forget('two_factor_confirmed_at');

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Autentikasi dua faktor telah dinonaktifkan.',
            ]);
        }

        return back()->with('success', 'Autentikasi dua faktor (2FA) telah dinonaktifkan.');
    }

    /**
     * Menampilkan formulir tantangan 2FA untuk pending login atau aksi berisiko tinggi (step-up).
     */
    public function challenge(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        $pendingId = $request->session()->get('login.mfa_user_id');

        if (! $user && ! $pendingId) {
            return redirect()->route('login');
        }

        if (! $user && $pendingId) {
            $startedAt = (int) $request->session()->get('login.mfa_started_at', 0);
            if (now()->timestamp - $startedAt > 300) {
                $request->session()->forget(['login.mfa_user_id', 'login.mfa_remember', 'login.mfa_started_at']);

                return redirect()->route('login')->withErrors(['email' => 'Sesi verifikasi 2FA telah kedaluwarsa. Silakan login kembali.']);
            }
        }

        return view('auth.two-factor-challenge');
    }

    /**
     * Memverifikasi kode tantangan 2FA atau kode pemulihan darurat.
     */
    public function verifyChallenge(Request $request): RedirectResponse|JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string'],
        ]);

        $user = $request->user();
        $isPendingLogin = false;

        if (! $user) {
            $pendingId = $request->session()->get('login.mfa_user_id');
            $startedAt = (int) $request->session()->get('login.mfa_started_at', 0);

            if (! $pendingId || (now()->timestamp - $startedAt > 300)) {
                $request->session()->forget(['login.mfa_user_id', 'login.mfa_remember', 'login.mfa_started_at']);

                if ($request->wantsJson()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Sesi verifikasi 2FA telah kedaluwarsa. Silakan login kembali.',
                    ], 401);
                }

                return redirect()->route('login')->withErrors(['email' => 'Sesi verifikasi 2FA telah kedaluwarsa. Silakan login kembali.']);
            }

            $user = User::withoutGlobalScopes()->find($pendingId);

            if (! $user || ! $user->hasTwoFactorEnabled() || ! $user->is_active || (bool) $user->tenant?->isSuspended()) {
                $request->session()->forget(['login.mfa_user_id', 'login.mfa_remember', 'login.mfa_started_at']);

                if ($request->wantsJson()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Akun tidak valid atau tidak memiliki akses.',
                    ], 403);
                }

                return redirect()->route('login')->withErrors(['email' => 'Akun tidak valid atau tidak memiliki akses.']);
            }

            $isPendingLogin = true;
        }

        $input = trim((string) $request->input('code'));
        $verified = false;

        // Cek apakah input adalah recovery code (panjang > 6 atau ada tanda '-')
        if (str_contains($input, '-') || strlen($input) > 6) {
            $verified = $this->twoFactorService->verifyRecoveryCode($user, $input);
        } else {
            $secret = $user->two_factor_secret;
            if (! empty($secret)) {
                $verified = $this->twoFactorService->verifyCode($secret, $input);
            }
        }

        if (! $verified) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Kode autentikasi dua faktor atau kode pemulihan tidak valid.',
                ], 422);
            }

            return back()->withErrors(['code' => 'Kode autentikasi dua faktor atau kode pemulihan tidak valid.']);
        }

        if ($isPendingLogin) {
            $remember = (bool) $request->session()->get('login.mfa_remember', false);
            $request->session()->forget(['login.mfa_user_id', 'login.mfa_remember', 'login.mfa_started_at']);

            Auth::guard('web')->login($user, $remember);
            $request->session()->regenerate();
            $request->session()->put('two_factor_confirmed_at', now()->timestamp);

            $this->auditTrail->log(
                tenantId: (int) $user->tenant_id,
                action: 'user.login_mfa_completed',
                userId: (int) $user->id,
                subjectType: User::class,
                subjectId: (int) $user->id,
                description: "Pengguna {$user->email} berhasil menyelesaikan tantangan 2FA saat masuk."
            );

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'redirect_url' => route('home', absolute: false),
                ]);
            }

            return redirect()->intended(route('home', absolute: false));
        }

        $request->session()->put('two_factor_confirmed_at', now()->timestamp);

        $intendedUrl = $request->session()->pull('two_factor_intended_url', route('home'));

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'redirect_url' => $intendedUrl,
            ]);
        }

        return redirect()->to($intendedUrl)
            ->with('success', 'Identitas dua faktor berhasil diverifikasi.');
    }
}
