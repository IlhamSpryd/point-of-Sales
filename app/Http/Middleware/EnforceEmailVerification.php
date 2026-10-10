<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Domain 1 — D4 enforcement: Owner BELUM verifikasi email TIDAK boleh
 * memperoleh akses POS penuh.
 *
 * Pendekatan minimal-invasif (dokumentasi di PROVISIONING.md):
 *  - Terpasang pada grup web yang sudah 'auth' (SetTenantContext), tepat
 *    SETELAH konteks tenant diketahui.
 *  - Owner dikecualikan hanya bila belum verified → diarahkan ke halaman
 *    notice verifikasi (bukan 403) supaya tidak merusak UX existing;
 *    akun STAF existing (Manager/Kasir/dll) tidak terpengaruh sama sekali
 *    → kompatibilitas mundur terjaga.
 *  - Aksi yang diizinkan Owner unverified: logout, verifikasi email,
 *    onboarding self (butuh minimal session read-only).
 */
class EnforceEmailVerification
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->hasVerifiedEmail()) {
            return $next($request);
        }

        $isOwner = $user->role?->name === 'Owner' || (bool) ($user->role?->is_admin ?? false);

        if (! $isOwner) {
            return $next($request);
        }

        // Whitelist aksi yang boleh dilakukan owner unverified:
        $allowed = [
            'logout',
            'verification.notice',
            'verification.verify',
            'verification.send',
            'home', // pendaratan netral agar tidak 403 — diberi notice verifikasi
        ];
        if ($request->routeIs($allowed)) {
            return $next($request);
        }

        return redirect()->route('verification.notice');
    }
}
