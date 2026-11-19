<?php

namespace App\Modules\Organization\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRoleRequest;
use App\Http\Requests\UpdateRoleRequest;
use App\Modules\Organization\Models\Role;
use App\Modules\Organization\Services\RoleService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
    public function index(Request $request): View|BinaryFileResponse
    {
        $this->authorize('viewAny', Role::class);

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
        $this->authorize('create', Role::class);

        return view('roles.create');
    }

    /**
     * Masukkan data baru dan setorkan dalam memori storage (DB).
     */
    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $this->authorize('create', Role::class);

        $validated = $request->validated();
        if ($request->user()?->role?->name === 'Admin' && strcasecmp((string) ($validated['name'] ?? ''), 'Owner') === 0) {
            abort(403, 'Administrators cannot create an Owner role.');
        }

        // Validasi input parameter oleh file Form Request.
        // Apabila telah tervalidasi benar, oper array-nya menuju Service.
        $this->roleService->store($validated);

        // Mengalihkan URL kembali ke index yang melampirkan sesi session message.
        return redirect()->route('roles.index')->with('success', 'Role created successfully.');
    }

    /**
     * Menampilkan form untuk menyunting resource yang dipilih.
     */
    public function edit(Role $role): View
    {
        $this->authorize('update', $role);

        return view('roles.edit', compact('role'));
    }

    /**
     * Sinkronisasikan perubahan spesifik ke target resource terkait.
     */
    public function update(UpdateRoleRequest $request, Role $role): RedirectResponse
    {
        $this->authorize('update', $role);

        $validated = $request->validated();
        if ($request->user()?->role?->name === 'Admin' && (strcasecmp((string) ($validated['name'] ?? ''), 'Owner') === 0 || $role->name === 'Owner')) {
            abort(403, 'Administrators cannot modify the Owner role.');
        }

        // Form request menjaga pengikatan data lalu divalidasi.
        // Lanjutkan perubahan properties role menuju Service Pattern.
        $this->roleService->update($role, $validated);

        return redirect()->route('roles.index')->with('success', 'Role updated successfully.');
    }

    /**
     * Duplikasi peran yang ada agar Owner mudah membuat custom role baru.
     */
    public function duplicate(Role $role): RedirectResponse
    {
        $this->authorize('create', Role::class);

        if (request()->user()?->role?->name === 'Admin' && $role->name === 'Owner') {
            abort(403, 'Administrators cannot duplicate the Owner role.');
        }

        $newRole = $this->roleService->duplicate($role);

        return redirect()->route('roles.edit', $newRole->id)->with('success', "Role '{$role->name}' duplicated successfully as '{$newRole->name}'. You can now customize permissions and archetype.");
    }

    /**
     * Menyapu nilai entitas dari dalam storage Database.
     */
    public function destroy(Role $role): RedirectResponse
    {
        $this->authorize('delete', $role);

        try {
            // Berikan model sasaran langsung ke dalam modul Service untuk dihapus nilainya.
            $this->roleService->delete($role);

            return redirect()->route('roles.index')->with('success', 'Role deleted successfully.');
        } catch (QueryException $e) {
            return redirect()->route('roles.index')->with('error', 'This role is linked to other system records and cannot be deleted.');
        } catch (\Throwable $e) {
            // Handle and report exception message.
            return redirect()->route('roles.index')->with('error', $e->getMessage());
        }
    }
}
