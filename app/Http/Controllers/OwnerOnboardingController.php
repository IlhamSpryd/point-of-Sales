<?php

namespace App\Http\Controllers;

use App\Models\OwnerOnboardingToken;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

/**
 * Domain 1 — penukaran magic link onboarding Owner pertama (D5).
 *
 * Sifat penting:
 *  - Token dinilai server-side berdasarkan SHA-256 hash; plaintext TIDAK
 *    tersimpan. Tidak bisa dipakai dua kali (consumed_at diisi atomik).
 *  - Token TERIKAT ke (user_id, tenant_id) — TIDAK mengubah email/tenant.
 *  - Password ditetapkan via POST HTTPS + validasi Rules\Password, di-hash
 *    dengan Hash::make (cast 'password_hash' => 'hashed' di User).
 *  - Verifikasi email (email_verified_at) di-set pada penukaran yang SUKSES
 *    — satu langkah aktivasi yang sama.
 *  - Rate limit pada route (throttle:6,1).
 *  - Tidak mengembalikan detail apapun tentang token invalid selain generic.
 */
class OwnerOnboardingController extends Controller
{
    public function show(string $token): View|RedirectResponse
    {
        $record = $this->findValidToken($token);

        if ($record === null) {
            return redirect()->route('login')
                ->with('error', 'Tautan aktivasi tidak valid atau sudah kedaluwarsa.');
        }

        return view('auth.onboarding-set-password', [
            'token' => $token,
            'tenantName' => $record->tenant->name ?? '',
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $record = $this->findValidToken($token);

        if ($record === null) {
            return redirect()->route('login')
                ->with('error', 'Tautan aktivasi tidak valid atau sudah kedaluwarsa.');
        }

        // Penukaran sekali-pakai secara atomik: conditional update di dalam
        // transaksi. Bila dua tab membuka link yang sama, hanya satu yang
        // berhasil menetapkan consumed_at => lainnya akan gagal konsumsi.
        $success = DB::transaction(function () use ($record, $request) {
            $claimed = OwnerOnboardingToken::where('id', $record->id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            if ($claimed !== 1) {
                return false;
            }

            $user = User::withTrashed()->findOrFail($record->user_id);

            // Guard: token terikat ke user & tenant spesifik; tidak mengubah
            // email. User harus belum punya password (owner baru provisioning).
            if ($user->trashed() || $user->tenant_id !== $record->tenant_id) {
                return false;
            }

            $user->forceFill([
                'password_hash' => Hash::make($request->string('password')),
                'email_verified_at' => now(), // D4 — verifikasi email sekaligus aktivasi
            ])->save();

            return true;
        });

        if (! $success) {
            return redirect()->route('login')
                ->with('error', 'Tautan aktivasi sudah pernah digunakan.');
        }

        return redirect()->route('login')
            ->with('success', 'Password berhasil ditetapkan. Silakan login.');
    }

    /**
     * Cari token valid: hash cocok, belum dipakai, belum kedaluwarsa,
     * user masih aktif. Plaintext token tidak pernah disimpan.
     */
    private function findValidToken(string $plaintext): ?OwnerOnboardingToken
    {
        if ($plaintext === '' || strlen($plaintext) > 255) {
            return null;
        }

        $record = OwnerOnboardingToken::where('token_hash', hash('sha256', $plaintext))
            ->first();

        if ($record === null || $record->isConsumed() || $record->isExpired()) {
            return null;
        }

        $user = User::find($record->user_id);
        if ($user === null || ! $user->is_active) {
            return null;
        }

        return $record;
    }
}
