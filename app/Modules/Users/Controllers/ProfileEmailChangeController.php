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
                'current_password.required' => 'Kata sandi saat ini wajib diisi untuk verifikasi keamanan.',
                'current_password.current_password' => 'Kata sandi saat ini tidak cocok.',
            ]);
        }

        // 2. Validasi format email baru
        $request->validate([
            'new_email' => ['required', 'string', 'email', 'max:255'],
        ], [
            'new_email.required' => 'Alamat email baru wajib diisi.',
            'new_email.email' => 'Format alamat email baru tidak valid.',
        ]);

        $newEmail = (string) $request->input('new_email');
        $newNormalized = EmailNormalizer::normalize($newEmail);

        if ($newNormalized === $user->email_normalized) {
            return back()->withErrors(['new_email' => 'Alamat email baru tidak boleh sama dengan email aktif saat ini.']);
        }

        // 3. Pengecekan konflik global pada users.email_normalized
        $conflict = User::withoutGlobalScopes()
            ->where('email_normalized', $newNormalized)
            ->where('id', '!=', $user->id)
            ->whereNull('deleted_at')
            ->exists();

        if ($conflict) {
            return back()->withErrors(['new_email' => 'Alamat email ini sudah terdaftar pada akun lain.']);
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
            'subject' => 'Peringatan Keamanan: Permintaan Perubahan Alamat Email',
            'event_type' => 'email_change_requested',
            'body_text' => "Halo {$user->name},\n\nKami mendeteksi adanya permintaan untuk mengubah alamat email akun Anda menjadi {$newEmail}.\nJika Anda tidak merasa melakukan permintaan ini, segera amankan akun Anda dan ganti kata sandi.",
            'status' => 'pending',
        ]);

        return back()->with([
            'status' => 'pending-email-otp-sent',
            'success' => 'Kode OTP 6 digit telah dikirim ke '.$newEmail.'. Silakan verifikasi kode untuk menyelesaikan perubahan email.',
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
            'otp_code.required' => 'Kode OTP wajib diisi.',
            'otp_code.size' => 'Kode OTP harus 6 digit angka.',
        ]);

        /** @var User $user */
        $user = $request->user();

        return DB::transaction(function () use ($user, $request) {
            $lockedUser = User::where('id', $user->id)->lockForUpdate()->firstOrFail();

            if (empty($lockedUser->pending_email)) {
                return back()->with('error', 'Tidak ada permintaan perubahan email yang sedang berlangsung.');
            }

            // Verifikasi OTP
            $result = $this->otpService->verifyOtp($lockedUser, (string) $request->input('otp_code'), 'email_change');

            if (! $result->isSuccessful()) {
                if ($result->isLocked()) {
                    return back()->with('error', 'Kode terkunci karena terlalu banyak kesalahan percobaan. Silakan ajukan perubahan ulang.');
                }

                return back()->with('error', 'Kode OTP salah atau telah kedaluwarsa. Sisa percobaan: '.$result->getRemainingAttempts());
            }

            // Verifikasi konflik sekali lagi di bawah lock
            $conflict = User::withoutGlobalScopes()
                ->where('email_normalized', $lockedUser->pending_email_normalized)
                ->where('id', '!=', $lockedUser->id)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->exists();

            if ($conflict) {
                return back()->with('error', 'Alamat email baru telah terdaftar pada akun lain.');
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
                'subject' => 'Alamat Email Akun Apeiron POS Telah Berubah',
                'event_type' => 'email_change_completed',
                'body_text' => "Halo {$lockedUser->name},\n\nAlamat email akun Anda telah berhasil dialihkan dari {$oldEmail} ke {$newEmail}.",
                'status' => 'pending',
            ]);

            return back()->with([
                'status' => 'profile-email-updated',
                'success' => 'Alamat email berhasil diperbarui menjadi '.$newEmail.'.',
            ]);
        });
    }

    /**
     * Membatalkan permintaan perubahan email yang sedang pending.
     */
    public function cancelChange(Request $request): RedirectResponse
    {
        $user = $request->user();
        $user->forceFill([
            'pending_email' => null,
            'pending_email_normalized' => null,
            'pending_email_requested_at' => null,
        ])->save();

        return back()->with('success', 'Permintaan perubahan email telah dibatalkan.');
    }
}
