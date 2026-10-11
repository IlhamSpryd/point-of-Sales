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
 *    tersimpan.
 *  - Konsumsi token ATOMIK ({@see OwnerOnboardingToken::consume()}): dua tab
 *    yang membuka tautan sama hanya menghasilkan satu perubahan password.
 *  - Token TERIKAT ke (user_id, tenant_id). Penukaran TIDAK PERNAH mengubah
 *    email dan TIDAK memindahkan user antar tenant; user yang sudah pindah
 *    tenant (atau sudah di-soft-delete) ditolak.
 *  - Password ditetapkan via POST HTTPS + validasi Rules\Password, di-hash
 *    dengan Hash::make (cast 'password_hash' => 'hashed' di User).
 *  - Verifikasi email (email_verified_at) di-set pada penukaran yang SUKSES —
 *    satu langkah aktivasi yang sama.
 *  - Rate limit pada route (throttle:6,1).
 *  - Pesan galat bersifat generic: tidak membedakan "token tidak ada",
 *    "sudah dipakai", dan "kedaluwarsa".
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

        $result = DB::transaction(function () use ($record, $request) {
            // Konsumsi atomik: hanya pemanggil pertama yang berhasil.
            if (! $record->consume()) {
                return 'consumed';
            }

            $user = User::withTrashed()->find($record->user_id);

            // Guard lintas-tenant: token yang dibuat untuk user tenant A tidak
            // boleh mengubah user tenant B, walau barisnya sempat dipindahkan.
            if ($user === null || $user->trashed()) {
                return 'invalid';
            }

            if ((int) $user->tenant_id !== (int) $record->tenant_id) {
                return 'cross_tenant';
            }

            // Akun yang sudah punya password bukan lagi akun "onboarding";
            // token tidak boleh menimpanya.
            if (! empty($user->password_hash)) {
                return 'already_activated';
            }

            $user->forceFill([
                'password_hash' => Hash::make($request->string('password')),
                'email_verified_at' => now(), // D4 — verifikasi email sekaligus aktivasi
            ])->save();

            return 'ok';
        });

        return match ($result) {
            'ok' => redirect()->route('login')
                ->with('success', 'Password berhasil ditetapkan. Silakan login.'),
            'already_activated' => redirect()->route('login')
                ->with('error', 'Akun ini sudah pernah diaktifkan. Silakan login atau reset password.'),
            default => redirect()->route('login')
                ->with('error', 'Tautan aktivasi tidak valid atau sudah kedaluwarsa.'),
        };
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
