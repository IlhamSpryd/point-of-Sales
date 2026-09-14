<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Http\Requests\StoreRoleRequest;
use App\Http\Requests\UpdateRoleRequest;
use App\Services\RoleService;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;
use Exception;

/**
 * RoleController: Penghubung delegasi antarmuka permohonan manajemen Peran 
 * yang hanya bertugas melayangkan data bersih ke RoleService.
 */
class RoleController extends Controller
{
    /**
     * Memasukkan RoleService.
     * Seluruh Controller bersifat Tipis (Thin Controller), artinya proses CRUD ada di Service.
     */
    public function __construct(
        protected RoleService $roleService
    ) {}

    /**
     * Menampilkan daftar indeks Roles.
     */
    public function index(\Illuminate\Http\Request $request): \Illuminate\View\View|\Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        // Alihkan pengembalian menjadi file unduh (StreamResponse CSV) bila request adalah ekspor.
        if ($request->has('export') && $request->export === 'csv') {
            return $this->roleService->exportCsv($request);
        }

        // Ambil Roles berdasarkan parameter query request lalu susun per halaman (Pagination).
        $roles = $this->roleService->getFilteredQuery($request)->paginate(10)->withQueryString();
        // Berikan variabel data kepada tampilan dan sampaikan ke peramban 
        return view('roles.index', compact('roles'));
    }

    /**
     * Menampilkan form untuk membuat resource baru.
     */
    public function create(): View
    {
        return view('roles.create');
    }

    /**
     * Masukkan data baru dan setorkan dalam memori storage (DB).
     */
    public function store(StoreRoleRequest $request): RedirectResponse
    {
        // Validasi input parameter oleh file Form Request.
        // Apabila telah tervalidasi benar, oper array-nya menuju Service. 
        $this->roleService->store($request->validated());
        // Mengalihkan URL kembali ke index yang melampirkan sesi session message.
        return redirect()->route('roles.index')->with('success', 'Role created successfully.');
    }

    /**
     * Menampilkan form untuk menyunting resource yang dipilih.
     */
    public function edit(Role $role): View
    {
        return view('roles.edit', compact('role'));
    }

    /**
     * Sinkronisasikan perubahan spesifik ke target resource terkait.
     */
    public function update(UpdateRoleRequest $request, Role $role): RedirectResponse
    {
        // Form request menjaga pengikatan data lalu divalidasi. 
        // Lanjutkan perubahan properties role menuju Service Pattern.
        $this->roleService->update($role, $request->validated());
        return redirect()->route('roles.index')->with('success', 'Role updated successfully.');
    }

    /**
     * Menyapu nilai entitas dari dalam storage Database.
     */
    public function destroy(Role $role): RedirectResponse
    {
        try {
            // Berikan model sasaran langsung ke dalam modul Service untuk dihapus nilainya.
            $this->roleService->delete($role);
            return redirect()->route('roles.index')->with('success', 'Role deleted successfully.');
        } catch (Exception $e) {
            // Atasi lemparan kegagalan Exception bila terjadi dan beritahukan sebab kegagalannya. 
            return redirect()->route('roles.index')->with('error', $e->getMessage());
        }
    }
}
