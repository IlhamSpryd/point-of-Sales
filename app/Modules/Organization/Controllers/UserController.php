<?php

namespace App\Modules\Organization\Controllers;

use App\Core\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Modules\Organization\Models\Role;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Models\User;
use App\Modules\Organization\Models\UserInvitation;
use App\Modules\Organization\Services\UserService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
    public function index(Request $request): View|BinaryFileResponse
    {
        $this->authorize('viewAny', User::class);

        // Cek request untuk ekspor CSV, lalu delegasikan pembuatannya ke Service.
        if ($request->has('export') && $request->export === 'csv') {
            return $this->userService->exportCsv($request);
        }

        // Mengambil data pengguna dengan filter dari UserService berserta paginasinya
        $users = $this->userService->getFilteredQuery($request)->with('stores')->paginate(10)->withQueryString();
        $roles = Role::orderBy('name')->get();
        $stores = Store::where('tenant_id', app(TenantContext::class)->requireTenantId())->orderBy('name')->get();
        $invitations = UserInvitation::with(['role', 'invitedBy'])->latest('id')->limit(20)->get();

        // Mengembalikan View dan mengirim data users, roles, stores, invitations
        return view('users.index', compact('users', 'roles', 'stores', 'invitations'));
    }

    /**
     * Menampilkan form pembuatan data resource baru.
     */
    public function create(): View
    {
        $this->authorize('create', User::class);

        // Hanya role milik tenant pada konteks aktif (TenantScope global).
        $roles = Role::orderBy('name')->get();
        $stores = Store::where('tenant_id', app(TenantContext::class)->requireTenantId())->orderBy('name')->get();

        return view('users.create', compact('roles', 'stores'));
    }

    /**
     * Menyimpan entri resource baru ke dalam database.
     */
    public function store(StoreUserRequest $request): RedirectResponse
    {
        $this->authorize('create', User::class);

        $validated = $request->validated();
        if ($request->user()?->role?->name === 'Admin' && isset($validated['role_id'])) {
            $targetRole = Role::find($validated['role_id']);
            if ($targetRole && $targetRole->name === 'Owner') {
                abort(403, 'Administrators cannot assign the Owner role.');
            }
        }

        // Nilai masukan klien tervalidasi di Form Request (StoreUserRequest).
        // Parameter tervalidasi dioper ke dalam UserService untuk proses penyimpanan aktual.
        $this->userService->store($validated);

        // Melakukan Redirect ke halaman list dengan pesan sukses.
        return redirect()->route('users.index')->with('success', 'User created successfully.');
    }

    /**
     * Menampilkan form untuk menyunting resource terkait.
     */
    public function edit(User $user): View
    {
        $tenantId = (int) app(TenantContext::class)->requireTenantId();
        abort_if((int) $user->tenant_id !== $tenantId, 404);
        $this->authorize('update', $user);

        // Hanya role milik tenant pada konteks aktif (TenantScope global).
        $roles = Role::orderBy('name')->get();
        $stores = Store::where('tenant_id', $tenantId)->orderBy('name')->get();
        $user->loadMissing('stores');

        return view('users.edit', compact('user', 'roles', 'stores'));
    }

    /**
     * Memperbarui perincian resource di dalam database.
     */
    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $tenantId = (int) app(TenantContext::class)->requireTenantId();
        abort_if((int) $user->tenant_id !== $tenantId, 404);
        $this->authorize('update', $user);

        $validated = $request->validated();
        if ($request->user()?->role?->name === 'Admin' && isset($validated['role_id'])) {
            $targetRole = Role::find($validated['role_id']);
            if ($targetRole && $targetRole->name === 'Owner') {
                abort(403, 'Administrators cannot assign the Owner role.');
            }
        }

        // Aturan update diserahkan ke Form Request, dan validasi data disalurkan
        // kepada UserService yang akan menerapkan pembaharuan atas pengguna tersebut.
        $this->userService->update($user, $validated);

        return redirect()->route('users.index')->with('success', 'User updated successfully.');
    }

    /**
     * Menghapus resource dari database.
     */
    public function destroy(User $user): RedirectResponse
    {
        $tenantId = (int) app(TenantContext::class)->requireTenantId();
        abort_if((int) $user->tenant_id !== $tenantId, 404);
        $this->authorize('delete', $user);

        try {
            // Mendelegasikan operasi penghapusan ke Service.
            $this->userService->delete($user);

            return redirect()->route('users.index')->with('success', 'User deleted successfully.');
        } catch (QueryException $e) {
            return redirect()->route('users.index')->with('error', 'This user is linked to other system records and cannot be deleted.');
        } catch (\Throwable $e) {
            // Catch error exception if deletion fails.
            return redirect()->route('users.index')->with('error', $e->getMessage());
        }
    }
}
