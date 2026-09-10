<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RoleMiddleware: Membatasi akses ke route tertentu berdasarkan Role (peran) pengguna.
 * Digunakan pada definisi Route Group di web.php untuk menerapkan RBAC.
 *
 * Contoh penggunaan pada route:
 *   Route::middleware('role:Administrator')->group(...)
 *   Route::middleware('role:Administrator,Kasir')->group(...)
 */
class RoleMiddleware
{
    /**
     * Periksa apakah role pengguna yang terautentikasi termasuk dalam daftar role yang diperbolehkan.
     *
     * @param  string  ...$roles  Daftar nama role yang diperbolehkan (dipisahkan koma di route)
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        // Tolak akses jika belum login atau belum punya role
        if (!$user || !$user->role) {
            abort(403, 'Anda tidak memiliki hak akses untuk halaman ini.');
        }

        // Periksa apakah role user cocok dengan salah satu role yang diizinkan
        if (!in_array($user->role->name, $roles)) {
            abort(403, 'Anda tidak memiliki hak akses untuk halaman ini.');
        }

        return $next($request);
    }
}
