<?php

namespace App\Http\Middleware;

use App\Modules\Organization\Models\User;
use App\Platform\Navigation\NavigationRegistry;
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

        // Full access hanya untuk Super Admin / Owner dengan wildcard permission atau flag is_admin
        $isOwnerOrAdmin = (bool) ($user->role->is_admin ?? false)
            || in_array($user->role->name, ['Owner', 'Superadmin', 'Super Admin'], true);

        if ($isOwnerOrAdmin && ($user->hasPermission('*') || (bool) ($user->role->is_admin ?? false))) {
            return $next($request);
        }

        // Periksa apakah role user cocok dengan salah satu role yang diizinkan
        if (in_array($user->role->name, $roles, true)) {
            return $next($request);
        }

        $routeName = (string) ($request->route()?->getName() ?? '');

        // Dynamic Dashboard RBAC: Izinkan seluruh role (default & custom) yang memiliki permission 'dashboard'
        // atau konfigurasi dashboard_archetype untuk mengakses endpoint dasbor
        if (in_array($routeName, ['dashboard', 'dashboard.approve-ticket', 'dashboard.store-targets.save'], true)
            && ($user->hasPermission('dashboard') || $user->hasPermission('dashboard.action.approve_void_discount') || ! empty($user->role->dashboard_archetype))) {
            return $next($request);
        }

        // Dukungan Custom Role berbasis permission kanonikal:
        // Jika bukan role bawaan tetapi memiliki izin eksplisit pada rute yang sedang diakses
        $canonicalRoles = ['Owner', 'Admin', 'Manager', 'Finance', 'Sales', 'Kasir', 'Waiter', 'Barista', 'Inventory', 'Supervisor', 'Cook'];
        if (! in_array($user->role->name, $canonicalRoles, true)) {
            if ($routeName !== '' && $this->customRoleHasRoutePermission($user, $routeName)) {
                return $next($request);
            }
        }

        abort(403, 'Anda tidak memiliki hak akses untuk halaman ini.');
    }

    /**
     * Evaluate whether a Custom Role holds a canonical permission for the active route.
     *
     * ADR-NAV-006 / Governance Law G-3: authorization must not read the navigation
     * config. Resolution now comes from the NavigationRegistry's per-route
     * permission manifest, which is a pure lookup — not a menu scan. Navigation
     * stays advisory; this middleware stays authoritative.
     */
    private function customRoleHasRoutePermission(User $user, string $routeName): bool
    {
        /** @var NavigationRegistry $registry */
        $registry = app(NavigationRegistry::class);

        $candidates = $registry->permissionsForRoute($routeName);

        // Resolve wildcard route names too (e.g. a route matched by `orders.*`).
        if ($candidates === []) {
            foreach ($registry->pages() as $node) {
                if ($node->route === null) {
                    continue;
                }
                if ($this->matchesRoutePattern($node->active, $routeName)) {
                    foreach ($node->abilities as $ability) {
                        $candidates[] = $ability;
                    }
                }
            }
        }

        foreach (array_unique($candidates) as $permission) {
            if ($user->hasPermission($permission)) {
                return true;
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
