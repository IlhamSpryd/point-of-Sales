<?php

namespace App\Modules\Auth\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\EmailOutbox;
use App\Models\User;
use App\Support\EmailNormalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    /**
     * Merender antarmuka formulir pengajuan link perombakan kata sandi.
     */
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Memproses email pengajuan dengan Anti-Enumerasi dan merutekan notifikasi via EmailOutbox.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ], [
            'email.required' => 'Alamat email wajib diisi.',
            'email.email' => 'Format alamat email tidak valid.',
        ]);

        $rawEmail = (string) $request->input('email');
        $normalized = EmailNormalizer::normalize($rawEmail);

        // Cari pengguna aktif tanpa membocorkan eksistensinya ke publik (Anti-Enumerasi [REQ-SEC-23])
        $user = User::withoutGlobalScopes()
            ->where('email_normalized', $normalized)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->first();

        if ($user !== null) {
            $token = Password::broker()->createToken($user);
            $resetUrl = route('password.reset', ['token' => $token, 'email' => $user->email]);

            EmailOutbox::create([
                'tenant_id' => $user->tenant_id,
                'user_id' => $user->id,
                'recipient_email' => $user->email,
                'subject' => 'Permintaan Reset Kata Sandi Akun Apeiron POS',
                'event_type' => 'password_reset',
                'body_text' => "Halo {$user->name},\n\nKami menerima permintaan untuk mengatur ulang kata sandi akun Anda.\nSilakan klik tautan berikut untuk membuat kata sandi baru:\n{$resetUrl}\n\nTautan ini hanya berlaku selama 60 menit dan akan kedaluwarsa setelah digunakan.",
                'status' => 'pending',
            ]);
        }

        // Respons seragam untuk email yang ada maupun tidak ada
        return back()->with('status', 'Jika alamat email terdaftar di sistem kami, instruksi pemulihan kata sandi telah dikirim ke kotak masuk Anda.');
    }
}
