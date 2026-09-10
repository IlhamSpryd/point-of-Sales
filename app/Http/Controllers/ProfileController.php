<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

/**
 * ProfileController adalah pusat pengatur tampilan maupun pemrosesan
 * pembaruan riwayat sandi atau nama Profil bagi User yang sedang Login.
 */
class ProfileController extends Controller
{
    /**
     * Menampilkan antarmuka Halaman Profil milik Pengguna.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Menyimpan perubahan atribut personal dari Pengguna (Nama/Sandi).
     * Jika terjadi pergeseran alamat Email, status verifikasinya akan otomatis di-reset.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Melenyapkan (Hapus permanen) akun ini berdasarkan persetujuan final Pengguna yang bersangkutan.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
