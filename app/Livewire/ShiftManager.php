<?php

namespace App\Livewire;

// [OMEGA-NODE1] Import QueryException untuk menangkap pelanggaran unique
// constraint DB (shifts_one_open_per_user_unique) sebagai sinyal otoritatif
// "shift sudah open", bukan lagi sekadar pengecekan aplikasi | 2026-09-21
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Shift;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class ShiftManager extends Component
{
    public $activeShift;

    public $opening_balance = 0;

    public $closing_balance = 0;

    public $notes = '';

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
        $this->validate([
            'opening_balance' => 'required|integer|min:0',
        ]);

        if (Shift::where('user_id', auth()->id())->where('status', 'open')->exists()) {
            $this->loadActiveShift();
            $this->dispatch('toast', message: 'Anda sudah memiliki shift yang sedang aktif.', type: 'info');

            return;
        }

        try {
            Shift::create([
                'user_id' => auth()->id(),
                'opening_balance' => (int) $this->opening_balance,
                'status' => 'open',
                'opened_at' => now(),
            ]);
        } catch (QueryException $e) {
            if (! str_contains($e->getMessage(), 'shifts_one_open_per_user_unique')) {
                throw $e;
            }

            $this->loadActiveShift();
            $this->dispatch('toast', message: 'Anda sudah memiliki shift yang sedang aktif.', type: 'info');

            return;
        }

        $this->loadActiveShift();
        $this->dispatch('toast', message: 'Shift berhasil dibuka.', type: 'success');
    }

    public function closeShift()
    {
        // [OMEGA-NODE1] integer, bukan numeric -- Rupiah tidak punya
        // subunit desimal praktis (konsisten dengan pola BIGINT UNSIGNED
        // yang sudah ditegakkan di kolom finansial orders) | 2026-09-21
        $this->validate([
            'closing_balance' => 'required|integer|min:0',
            'notes' => 'nullable|string|max:1000',
        ]);

        $closed = DB::transaction(function () {
            // Ambil ulang + kunci baris: $this->activeShift bisa basi (tab lain sudah menutupnya).
            $shift = Shift::where('user_id', auth()->id())
                ->where('status', 'open')
                ->lockForUpdate()
                ->first();

            if (! $shift) {
                return false;
            }

            $cashSales = (int) Order::where('shift_id', $shift->id)
                ->where('payment_method', 'cash')
                ->where('order_status', OrderStatus::Paid->value)
                ->sum('order_amount');

            $expectedCash = (int) $shift->opening_balance + $cashSales;
            $closing = (int) $this->closing_balance;

            $shift->update([
                'closing_balance' => $closing,
                'expected_cash' => $expectedCash,
                'cash_difference' => $closing - $expectedCash,
                'notes' => $this->notes,
                'status' => 'closed',
                'closed_at' => now(),
            ]);

            return true;
        });

        $this->loadActiveShift();

        if (! $closed) {
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
        if ($this->activeShift) {
            $cashSales = Order::where('shift_id', $this->activeShift->id)
                ->where('payment_method', 'cash')
                ->where('order_status', OrderStatus::Paid->value)
                ->sum('order_amount');
        }

        return view('livewire.shift-manager', [
            'cashSales' => $cashSales,
            'expectedCash' => $this->activeShift ? ($this->activeShift->opening_balance + $cashSales) : 0,
        ]);
    }
}
