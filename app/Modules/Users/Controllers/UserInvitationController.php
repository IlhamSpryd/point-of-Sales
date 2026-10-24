<?php

namespace App\Modules\Users\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserInvitationRequest;
use App\Models\UserInvitation;
use App\Services\Context\TenantContext;
use App\Services\UserInvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class UserInvitationController extends Controller
{
    public function __construct(
        protected UserInvitationService $invitationService
    ) {}

    /**
     * Menampilkan daftar riwayat dan status undangan staf untuk tenant aktif.
     */
    public function index(Request $request): View
    {
        $tenantId = app(TenantContext::class)->requireTenantId();
        Gate::authorize('viewAny', UserInvitation::class);

        $invitations = UserInvitation::where('tenant_id', $tenantId)
            ->with(['role', 'invitedBy', 'stores'])
            ->latest('id')
            ->paginate(15);

        return view('users.invitations', compact('invitations'));
    }

    /**
     * Menerbitkan dan mengirimkan undangan staf baru.
     */
    public function store(StoreUserInvitationRequest $request): RedirectResponse
    {
        try {
            $this->invitationService->issueInvitation($request->user(), $request->validated());

            return redirect()->route('users.index')
                ->with('success', 'Undangan karyawan berhasil dikirim.');
        } catch (\DomainException $e) {
            return redirect()->back()
                ->withInput()
                ->with('error', $e->getMessage());
        }
    }

    /**
     * Mengirim ulang undangan staf (membatalkan token lama dan menerbitkan token baru).
     */
    public function resend(Request $request, int $id): RedirectResponse
    {
        Gate::authorize('create', UserInvitation::class);

        $this->invitationService->resendInvitation($request->user(), $id);

        return redirect()->back()
            ->with('success', 'Undangan telah dikirim ulang.');
    }

    /**
     * Mencabut undangan staf yang masih berstatus pending.
     */
    public function revoke(Request $request, int $id): RedirectResponse
    {
        Gate::authorize('create', UserInvitation::class);

        $this->invitationService->revokeInvitation($request->user(), $id);

        return redirect()->back()
            ->with('success', 'Undangan berhasil dicabut.');
    }

    /**
     * Halaman publik untuk penerimaan undangan staf oleh calon karyawan.
     */
    public function showAccept(string $token): View
    {
        $tokenHash = hash('sha256', $token);

        /** @var UserInvitation|null $invitation */
        $invitation = UserInvitation::where('token_hash', $tokenHash)->first();

        if (! $invitation) {
            abort(404, 'Tautan undangan tidak valid atau tidak ditemukan.');
        }

        if ($invitation->status === UserInvitation::STATUS_BLOCKED_CROSS_TENANT) {
            abort(409, 'Akun dengan email ini sedang aktif pada organisasi lain.');
        }

        if (! $invitation->isPending()) {
            abort(410, 'Undangan ini telah digunakan, dicabut, digantikan, atau telah kedaluwarsa.');
        }

        return view('auth.accept-invitation', [
            'invitation' => $invitation,
            'token' => $token,
        ]);
    }

    /**
     * Memproses penerimaan undangan staf dan pembuatan kredensial login.
     */
    public function processAccept(Request $request, string $token): RedirectResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'password.required' => 'Kata sandi wajib diisi.',
            'password.min' => 'Kata sandi minimal 8 karakter.',
            'password.confirmed' => 'Konfirmasi kata sandi tidak cocok.',
        ]);

        $user = $this->invitationService->acceptInvitation($token, $validated);

        Auth::login($user);
        $request->session()->regenerate();
        app(TenantContext::class)->setTenantId($user->tenant_id);

        return redirect()->route('dashboard')
            ->with('success', 'Selamat datang! Akun karyawan Anda telah aktif.');
    }
}
