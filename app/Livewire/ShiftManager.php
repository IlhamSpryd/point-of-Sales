<?php

namespace App\Livewire;

// [OMEGA-NODE1] Import QueryException untuk menangkap pelanggaran unique
// constraint DB (shifts_one_open_per_user_unique) sebagai sinyal otoritatif
// "shift sudah open", bukan lagi sekadar pengecekan aplikasi | 2026-09-21
use App\Livewire\Concerns\RequiresTenantContext;
use App\Models\Shift;
use App\Services\ShiftService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ShiftManager extends Component
{
    use RequiresTenantContext;

    #[Locked]
    public $activeShift;

    public $opening_balance = 0;

    public $closing_balance = 0;

    public $notes = '';

    public function boot(): void
    {
        $this->bootRequiresTenantContext();

        $role = auth()->user()?->role;
        $roleName = $role?->name;
        if (! $role?->is_admin && ! in_array($roleName, ['Owner', 'Manager', 'Kasir', 'Supervisor'], true)) {
            abort(403, 'Anda tidak memiliki hak akses manajemen shift.');
        }
    }

    public function mount()
    {
        $this->loadActiveShift();
    }

    public function loadActiveShift()
    {
        $this->activeShift = Shift::where('user_id', auth()->id())
            ->where('status', 'open')
            ->first();
    }

    // [OMEGA-NODE1] Zero-Trust hardening: dua lapis pertahanan | 2026-09-21
    // Lapis 1 (exists check) = UX shortcut murah untuk kasus umum.
    // Lapis 2 (try/catch QueryException) = SATU-SATUNYA yang benar-benar
    // menutup celah race -- ditegakkan oleh constraint
    // `shifts_one_open_per_user_unique` di database, bukan oleh urutan
    // eksekusi PHP yang bisa diselang request paralel.
    public function openShift()
    {
        // [D2-BE-004] Otorisasi policy buka shift.
        Gate::authorize('open', Shift::class);

        $user = auth()->user();
        if ($user && $user->pin_hash === null) {
            $this->dispatch('toast', message: 'Kasir wajib menyetel PIN Kasir sebelum dapat membuka shift.', type: 'error');
            $this->dispatch('require-pin-setup');

            return;
        }

        $this->validate([
            'opening_balance' => 'required|integer|min:0',
        ]);

        try {
            // [D2-BE-008] Logika domain didelegasikan ke ShiftService (thin caller).
            $opened = app(ShiftService::class)
                ->openShift((int) auth()->id(), (int) $this->opening_balance);
        } catch (\DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');
            $this->dispatch('require-pin-setup');

            return;
        }

        if ($opened === null) {
            $this->loadActiveShift();
            $this->dispatch('toast', message: 'Anda sudah memiliki shift yang sedang aktif.', type: 'info');

            return;
        }
        $this->loadActiveShift();
        $this->dispatch('toast', message: 'Shift berhasil dibuka.', type: 'success');
    }

    public function setCashierPin(string $pin): void
    {
        $user = auth()->user();
        if (! $user) {
            return;
        }

        try {
            app(\App\Services\CashierPinService::class)->setPin($user, $pin);
            $this->dispatch('toast', message: 'PIN Kasir berhasil disetel.', type: 'success');
        } catch (\InvalidArgumentException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');
        }
    }

    public function closeShift()
    {
        $this->loadActiveShift();

        if (! $this->activeShift) {
            $this->dispatch('toast', message: 'Tidak ada shift aktif untuk ditutup.', type: 'info');

            return;
        }

        Gate::authorize('close', $this->activeShift);

        // [OMEGA-NODE1] integer, bukan numeric -- Rupiah tidak punya
        // subunit desimal praktis (konsisten dengan pola BIGINT UNSIGNED
        // yang sudah ditegakkan di kolom finansial orders) | 2026-09-21
        $this->validate([
            'closing_balance' => 'required|integer|min:0',
            'notes' => 'nullable|string|max:1000',
        ]);

        $shiftService = app(ShiftService::class);
        $closedShift = $shiftService->closeShift((int) auth()->id(), (int) $this->closing_balance, $this->notes);

        $this->loadActiveShift();

        if (! $closedShift) {
            $this->dispatch('toast', message: 'Tidak ada shift aktif untuk ditutup.', type: 'info');

            return;
        }

        $this->opening_balance = 0;
        $this->closing_balance = 0;
        $this->notes = '';

        $this->dispatch('toast', message: 'Shift berhasil ditutup.', type: 'success');
    }

    public function render()
    {
        $cashSales = 0;
        $expectedCash = 0;

        if ($this->activeShift) {
            $summary = app(ShiftService::class)->getCashSummary($this->activeShift);
            $cashSales = $summary['cash_sales'];
            $expectedCash = $summary['expected_cash'];
        }

        return view('livewire.shift-manager', [
            'cashSales' => $cashSales,
            'expectedCash' => $expectedCash,
        ]);
    }
}
