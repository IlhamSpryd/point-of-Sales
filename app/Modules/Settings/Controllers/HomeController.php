<?php

namespace App\Modules\Settings\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

/**
 * Pendaratan netral-role setelah login.
 *
 * Menggantikan redirect langsung ke route('dashboard') yang 403 untuk
 * role non-Owner/Manager (Supervisor, Cook, dll). Controller ini TIDAK
 * pernah 403 — kalau role tidak punya modul yang dipetakan, tampilkan
 * layar aman dengan tombol logout, bukan error polos tanpa jalan keluar.
 *
 * @see §2.1, §2.3 dari audit navigasi
 */
class HomeController extends Controller
{
    public function index(): View|RedirectResponse
    {
        $user = auth()->user();
        abort_unless($user !== null, 401);
        $roleName = $user->role?->name;

        // Jika Owner belum menyelesaikan wizard onboarding, arahkan ke wizard step yang aktif
        if (($roleName === 'Owner' || (bool) ($user->role->is_admin ?? false)) && config('pos.enforce_onboarding_wizard', true)) {
            $tenant = $user->tenant;
            if ($tenant && ! $tenant->isOnboardingCompleted()) {
                $tenant = $tenant->fresh() ?? $tenant;
            }

            if ($tenant && ! $tenant->isOnboardingCompleted()) {
                if ($tenant->onboarding_status === Tenant::ONBOARDING_INITIAL_BRANCH) {
                    return redirect()->route('onboarding.initial-branch');
                }

                return redirect()->route('onboarding.business-profile');
            }
        }

        $roleHome = match ($roleName) {
            'Kasir' => 'transaction.create',
            'Manager' => 'reports.sales',
            'Barista', 'Waiter' => 'kds.index',
            'Cook' => 'kds.index',
            'Inventory' => 'inventory.ingredients',
            'Supervisor' => 'shifts.index',
            'Owner', 'Admin' => 'dashboard',
            'Finance' => 'finance.index',
            'Sales' => 'sales.orders.index',
            default => null,
        };

        if ($roleHome && Route::has($roleHome)) {
            return redirect()->route($roleHome);
        }

        // Dynamic Custom Role Resolver: cari destinasi pertama yang diizinkan oleh permission peran kustom
        if ($user->role) {
            foreach (config('navigation.sections', []) as $items) {
                foreach ($items as $item) {
                    $routeName = $item['route'] ?? null;
                    if (! $routeName || ! Route::has($routeName)) {
                        continue;
                    }
                    $permission = $item['permission'] ?? null;
                    if (
                        in_array($roleName, $item['roles'] ?? [], true)
                        || ($permission && $user->hasPermission($permission))
                    ) {
                        return redirect()->route($routeName);
                    }
                }
            }
        }

        // Role custom tanpa izin atau role tanpa pemetaan — tampilkan halaman aman
        // dengan tombol logout, BUKAN 403 polos tanpa navigasi.
        return view('home.no-access');
    }
}
