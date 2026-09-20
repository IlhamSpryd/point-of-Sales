<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Sentral otentikasi request: Penengah dari proses Rate-Limiting dan verifikasi kredensial login.
 */
class LoginRequest extends FormRequest
{
    /**
     * Tentukan apakah guest (tamu anonim) diperkenankan mengirimkan formulasi request Login ini.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Daftar aturan validasi dari kerangka isian pendaftaran Login (Email terformat string dan ada Sandi).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Percobaan pembandingan kredensial yang masuk dalam form dengan nilai terenkripsi di Database (Attempt).
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        // [SEC-004 - CRITICAL FIX - AUDIT KEAMANAN]
        // SEBELUM perbaikan ini, kolom `users.is_active` HANYA bersifat
        // kosmetik: ditampilkan di UI sebagai toggle "Karyawan Aktif" /
        // "Nonaktif" (lihat users/create.blade.php, users/edit.blade.php),
        // tapi TIDAK PERNAH benar-benar dicek di jalur otentikasi. Guard
        // SessionGuard bawaan Laravel (Auth::attempt) hanya mencocokkan
        // email + hash password, sama sekali tidak tahu soal is_active.
        //
        // Akibatnya: karyawan yang di-nonaktifkan Owner (resign, dipecat,
        // atau bahkan sedang diselidiki dugaan kecurangan kas) TETAP BISA
        // login dan memproses transaksi selama masih ingat password lamanya
        // -- kegagalan kontrol akses paling berbahaya untuk sistem yang
        // menangani uang tunai secara langsung.
        //
        // Pengecekan WAJIB dilakukan SETELAH Auth::attempt() berhasil
        // (bukan sebelum, dan bukan lewat query terpisah sebelum attempt),
        // supaya kita hanya membocorkan informasi "akun ini nonaktif"
        // kepada seseorang yang SUDAH terbukti tahu password yang benar --
        // ini mencegah celah User Enumeration lewat perbedaan pesan error.
        $user = Auth::user();

        if ($user instanceof User && ! $user->is_active) {
            Auth::logout();

            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => 'Akun ini telah dinonaktifkan. Silakan hubungi Owner atau Manager.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Memastikan request pengajuan masuknya sandi tidak melanggar batas cegah percobaan peretasan Brute-Force (Rate Limiting).
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Menghasilkan pelacak unik dari IP Klien dan Email untuk keperluan pembekuan sementara (Throttle Key).
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
