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
 *   Route::middleware('role:Owner')->group(...)
 *   Route::middleware('role:Owner,Kasir')->group(...)
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
        if (! $user || ! $user->role) {
            abort(403, 'Anda tidak memiliki hak akses untuk halaman ini.');
        }

        // Periksa apakah role user cocok dengan salah satu role yang diizinkan
        if (in_array($user->role->name, $roles, true)) {
            return $next($request);
        }

        // Dukungan Custom Role berbasis permission kanonikal:
        // Jika bukan role bawaan tetapi memiliki izin eksplisit pada rute yang sedang diakses
        $canonicalRoles = ['Owner', 'Admin', 'Manager', 'Finance', 'Sales', 'Kasir', 'Waiter', 'Barista', 'Inventory', 'Supervisor', 'Cook'];
        if (! in_array($user->role->name, $canonicalRoles, true)) {
            $routeName = (string) ($request->route()?->getName() ?? '');
            if ($routeName !== '' && $this->customRoleHasRoutePermission($user, $routeName)) {
                return $next($request);
            }
        }

        abort(403, 'Anda tidak memiliki hak akses untuk halaman ini.');
    }

    /**
     * Evaluasi apakah Custom Role memiliki permission kanonikal yang terhubung dengan route aktif.
     */
    private function customRoleHasRoutePermission(\App\Models\User $user, string $routeName): bool
    {
        foreach (config('navigation.sections', []) as $items) {
            foreach ($items as $item) {
                $itemRoute = (string) ($item['route'] ?? '');
                $permission = (string) ($item['permission'] ?? '');
                if ($permission !== '' && ($itemRoute === $routeName || $this->matchesRoutePattern($item['active'] ?? null, $routeName))) {
                    if ($user->hasPermission($permission)) {
                        return true;
                    }
                }
            }
        }

        foreach (config('navigation.sub_nav', []) as $moduleConfig) {
            foreach ($moduleConfig['items'] ?? [] as $subItem) {
                $subRoute = (string) ($subItem['route'] ?? '');
                $permission = (string) ($subItem['permission'] ?? '');
                if ($permission !== '' && ($subRoute === $routeName || $this->matchesRoutePattern($subItem['active'] ?? null, $routeName))) {
                    if ($user->hasPermission($permission)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function matchesRoutePattern(mixed $patterns, string $routeName): bool
    {
        foreach ((array) $patterns as $pattern) {
            if (is_string($pattern) && $pattern !== '' && fnmatch($pattern, $routeName)) {
                return true;
            }
        }

        return false;
    }
}
