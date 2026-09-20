<?php

namespace App\Livewire;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Shift;
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

    public function openShift()
    {
        $this->validate([
            'opening_balance' => 'required|numeric|min:0',
        ]);

        // Guard: satu kasir hanya boleh punya satu shift terbuka (cegah dobel klik / dua tab).
        $alreadyOpen = Shift::where('user_id', auth()->id())->where('status', 'open')->exists();

        if ($alreadyOpen) {
            $this->loadActiveShift();
            $this->dispatch('toast', message: 'Anda sudah memiliki shift yang sedang aktif.', type: 'info');

            return;
        }

        Shift::create([
            'user_id' => auth()->id(),
            'opening_balance' => (int) $this->opening_balance,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $this->loadActiveShift();
        $this->dispatch('toast', message: 'Shift berhasil dibuka.', type: 'success');
    }

    public function closeShift()
    {
        $this->validate([
            'closing_balance' => 'required|numeric|min:0',
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
