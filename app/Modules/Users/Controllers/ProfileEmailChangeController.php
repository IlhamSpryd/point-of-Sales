<?php

namespace App\Modules\Users\Controllers;

use App\Exceptions\OtpRateLimitedException;
use App\Http\Controllers\Controller;
use App\Models\EmailOutbox;
use App\Models\User;
use App\Services\EmailVerificationOtpService;
use App\Support\EmailNormalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProfileEmailChangeController extends Controller
{
    public function __construct(
        protected EmailVerificationOtpService $otpService
    ) {}

    /**
     * Mengajukan perubahan email: validasi current_password, simpan ke pending_email,
     * dan kirim OTP 6-digit ke email baru tanpa merusak email aktif saat ini.
     */
    public function requestChange(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        // 1. Wajibkan current_password jika user memiliki password
        if ($user->password_hash !== null) {
            $request->validate([
                'current_password' => ['required', 'current_password'],
            ], [
                'current_password.required' => 'Current password is required for security verification.',
                'current_password.current_password' => 'The provided password does not match your current password.',
            ]);
        }

        // 2. Validasi format email baru
        $request->validate([
            'new_email' => ['required', 'string', 'email', 'max:255'],
        ], [
            'new_email.required' => 'New email address is required.',
            'new_email.email' => 'The new email address must be a valid email format.',
        ]);

        $newEmail = (string) $request->input('new_email');
        $newNormalized = EmailNormalizer::normalize($newEmail);

        if ($newNormalized === $user->email_normalized) {
            return back()->withErrors(['new_email' => 'The new email address cannot be the same as your current active email.']);
        }

        // 3. Pengecekan konflik global pada users.email_normalized
        $conflict = User::withoutGlobalScopes()
            ->where('email_normalized', $newNormalized)
            ->where('id', '!=', $user->id)
            ->whereNull('deleted_at')
            ->exists();

        if ($conflict) {
            return back()->withErrors(['new_email' => 'This email address is already registered to another account.']);
        }

        // 4. Simpan ke pending_email (users.email & email_verified_at TIDAK BERUBAH)
        $user->forceFill([
            'pending_email' => $newEmail,
            'pending_email_normalized' => $newNormalized,
            'pending_email_requested_at' => now(),
        ])->save();

        // 5. Terbitkan 6-digit OTP ke email baru
        try {
            $this->otpService->issueOtp($user, 'email_change', $request->ip(), $request->userAgent());
        } catch (OtpRateLimitedException $e) {
            return back()->with('error', $e->getMessage());
        }

        // 6. Kirim peringatan keamanan ke email lama via EmailOutbox
        EmailOutbox::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'recipient_email' => $user->email,
            'subject' => 'Security Alert: Email Address Change Requested',
            'event_type' => 'email_change_requested',
            'body_text' => "Hello {$user->name},\n\nA request to change your account email address to {$newEmail} has been received.\nIf you did not make this request, please secure your account immediately and change your password.",
            'status' => 'pending',
        ]);

        return back()->with([
            'status' => 'pending-email-otp-sent',
            'success' => 'A 6-digit verification code has been sent to '.$newEmail.'. Please enter the code to complete your email change.',
        ]);
    }

    /**
     * Memverifikasi kode OTP 6-digit dan menukar (Swap) users.email dengan pending_email
     * di dalam transaksi atomik dengan lockForUpdate().
     */
    public function verifyChange(Request $request): RedirectResponse
    {
        $request->validate([
            'otp_code' => ['required', 'string', 'size:6'],
        ], [
            'otp_code.required' => 'Verification code is required.',
            'otp_code.size' => 'Verification code must be 6 digits.',
        ]);

        /** @var User $user */
        $user = $request->user();

        return DB::transaction(function () use ($user, $request) {
            $lockedUser = User::where('id', $user->id)->lockForUpdate()->firstOrFail();

            if (empty($lockedUser->pending_email)) {
                return back()->with('error', 'No active email change request was found.');
            }

            // Verifikasi OTP
            $result = $this->otpService->verifyOtp($lockedUser, (string) $request->input('otp_code'), 'email_change');

            if (! $result->isSuccessful()) {
                if ($result->isLocked()) {
                    return back()->with('error', 'Verification locked due to too many failed attempts. Please submit a new request.');
                }

                return back()->with('error', 'Invalid or expired verification code. Remaining attempts: '.$result->getRemainingAttempts());
            }

            // Verifikasi konflik sekali lagi di bawah lock
            $conflict = User::withoutGlobalScopes()
                ->where('email_normalized', $lockedUser->pending_email_normalized)
                ->where('id', '!=', $lockedUser->id)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->exists();

            if ($conflict) {
                return back()->with('error', 'The new email address has already been registered to another account.');
            }

            $oldEmail = $lockedUser->email;
            $newEmail = $lockedUser->pending_email;
            $newNormalized = $lockedUser->pending_email_normalized;

            // SWAP ATOMIK: Tukar email baru ke kolom utama
            $lockedUser->forceFill([
                'email' => $newEmail,
                'email_normalized' => $newNormalized,
                'pending_email' => null,
                'pending_email_normalized' => null,
                'pending_email_requested_at' => null,
            ])->save();

            // Cabut seluruh sesi lain di tabel sessions
            try {
                DB::table('sessions')
                    ->where('user_id', $lockedUser->id)
                    ->where('id', '!=', session()->getId())
                    ->delete();
            } catch (\Throwable) {
            }

            // Notifikasi konfirmasi ke email lama via EmailOutbox
            EmailOutbox::create([
                'tenant_id' => $lockedUser->tenant_id,
                'user_id' => $lockedUser->id,
                'recipient_email' => $oldEmail,
                'subject' => 'Apeiron POS Account Email Address Changed',
                'event_type' => 'email_change_completed',
                'body_text' => "Hello {$lockedUser->name},\n\nYour account email address has been successfully changed from {$oldEmail} to {$newEmail}.",
                'status' => 'pending',
            ]);

            return back()->with([
                'status' => 'profile-email-updated',
                'success' => 'Email address successfully updated to '.$newEmail.'.',
            ]);
        });
    }

    /**
     * Membatalkan permintaan perubahan email yang sedang pending.
     */
    public function cancelChange(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);
        $user->forceFill([
            'pending_email' => null,
            'pending_email_normalized' => null,
            'pending_email_requested_at' => null,
        ])->save();

        return back()->with('success', 'Email change request has been cancelled.');
    }
}
