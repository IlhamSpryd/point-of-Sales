<?php

namespace App\Http\Controllers;

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
        $roleName = auth()->user()->role?->name;

        $roleHome = match ($roleName) {
            'Kasir' => 'transaction.create',
            'Manager' => 'reports.sales',
            'Barista', 'Waiter' => 'kds.index',
            'Cook' => 'kds.index',
            'Inventory' => 'inventory.ingredients',
            'Supervisor' => 'shifts.index',
            'Owner' => 'dashboard',
            default => null,
        };

        if ($roleHome && Route::has($roleHome)) {
            return redirect()->route($roleHome);
        }

        // Role custom atau role tanpa pemetaan — tampilkan halaman aman
        // dengan tombol logout, BUKAN 403 polos tanpa navigasi.
        return view('home.no-access');
    }
}
