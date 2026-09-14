<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Role;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Services\UserService;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;
use Exception;

/**
 * UserController: Bertanggung jawab penuh menjembatani lalu lintas data UI 
 * Pengguna dengan UserService guna memfasilitasi arsitektur Thin Controller.
 */
class UserController extends Controller
{
    /**
     * Dependency Injection untuk UserService.
     * Logika bisnis dipisah ke Service Pattern agar Controller tetap tipis (Thin Controller).
     */
    public function __construct(
        protected UserService $userService
    ) {}

    /**
     * Menampilkan daftar semua resource user.
     */
    public function index(\Illuminate\Http\Request $request): \Illuminate\View\View|\Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        // Cek request untuk ekspor CSV, lalu delegasikan pembuatannya ke Service.
        if ($request->has('export') && $request->export === 'csv') {
            return $this->userService->exportCsv($request);
        }

        // Mengambil data pengguna dengan filter dari UserService berserta paginasinya
        $users = $this->userService->getFilteredQuery($request)->paginate(10)->withQueryString();
        // Mengembalikan View dan mengirim data users
        return view('users.index', compact('users'));
    }

    /**
     * Menampilkan form pembuatan data resource baru.
     */
    public function create(): View
    {
        // Mengambil semua hak akses peran dari database
        $roles = Role::all();
        return view('users.create', compact('roles'));
    }

    /**
     * Menyimpan entri resource baru ke dalam database.
     */
    public function store(StoreUserRequest $request): RedirectResponse
    {
        // Nilai masukan klien tervalidasi di Form Request (StoreUserRequest).
        // Parameter tervalidasi dioper ke dalam UserService untuk proses penyimpanan aktual.
        $this->userService->store($request->validated());
        
        // Melakukan Redirect ke halaman list dengan pesan sukses.
        return redirect()->route('users.index')->with('success', 'User created successfully.');
    }

    /**
     * Menampilkan form untuk menyunting resource terkait.
     */
    public function edit(User $user): View
    {
        $roles = Role::all();
        return view('users.edit', compact('user', 'roles'));
    }

    /**
     * Memperbarui perincian resource di dalam database.
     */
    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        // Aturan update diserahkan ke Form Request, dan validasi data disalurkan
        // kepada UserService yang akan menerapkan pembaharuan atas pengguna tersebut.
        $this->userService->update($user, $request->validated());
        
        return redirect()->route('users.index')->with('success', 'User updated successfully.');
    }

    /**
     * Menghapus resource dari database.
     */
    public function destroy(User $user): RedirectResponse
    {
        try {
            // Mendelegasikan operasi penghapusan ke Service.
            $this->userService->delete($user);
            return redirect()->route('users.index')->with('success', 'User deleted successfully.');
        } catch (Exception $e) {
            // Menangkap potensi Error Exception bila penghapusan gagal. 
            return redirect()->route('users.index')->with('error', $e->getMessage());
        }
    }
}
