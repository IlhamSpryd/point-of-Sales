<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireTwoFactorChallenge
{
    /**
     * Mengharuskan konfirmasi 2FA segar pada aksi berisiko tinggi bagi pengguna yang mengaktifkan 2FA.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->hasTwoFactorEnabled()) {
            $confirmedAt = (int) $request->session()->get('two_factor_confirmed_at', 0);
            $freshThreshold = now()->subMinutes(30)->timestamp;

            if ($confirmedAt < $freshThreshold) {
                if ($request->expectsJson() || $request->is('api/*')) {
                    return response()->json([
                        'success' => false,
                        'code' => 'TWO_FACTOR_CHALLENGE_REQUIRED',
                        'message' => 'Tindakan sensitif ini memerlukan verifikasi kode autentikasi dua faktor (2FA).',
                    ], 423);
                }

                $request->session()->put('two_factor_intended_url', $request->fullUrl());

                return redirect()->route('two-factor.challenge')
                    ->with('warning', 'Tindakan ini sensitif. Masukkan kode 2FA Anda untuk melanjutkan.');
            }
        }

        return $next($request);
    }
}
