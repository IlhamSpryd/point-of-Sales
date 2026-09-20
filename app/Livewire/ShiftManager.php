<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Shift;
use App\Models\Order;

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
            'opening_balance' => 'required|numeric|min:0'
        ]);

        Shift::create([
            'user_id' => auth()->id(),
            'opening_balance' => $this->opening_balance,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $this->loadActiveShift();
        $this->dispatch('toast', message: 'Shift berhasil dibuka.');
    }

    public function closeShift()
    {
        $this->validate([
            'closing_balance' => 'required|numeric|min:0',
            'notes' => 'nullable|string'
        ]);

        // Hitung pemasukan tunai murni (cash) selama shift ini
        $cashSales = Order::where('shift_id', $this->activeShift->id)
            ->where('payment_method', 'cash')
            ->where('order_status', \App\Enums\OrderStatus::Paid->value)
            ->sum('order_amount');

        $expectedCash = $this->activeShift->opening_balance + $cashSales;
        $difference = $this->closing_balance - $expectedCash;

        $this->activeShift->update([
            'closing_balance' => $this->closing_balance,
            'expected_cash' => $expectedCash,
            'cash_difference' => $difference,
            'notes' => $this->notes,
            'status' => 'closed',
            'closed_at' => now(),
        ]);

        $this->loadActiveShift();
        $this->opening_balance = 0;
        $this->closing_balance = 0;
        $this->notes = '';
        
        $this->dispatch('toast', message: 'Shift berhasil ditutup.');
    }

    public function render()
    {
        $cashSales = 0;
        if ($this->activeShift) {
            $cashSales = Order::where('shift_id', $this->activeShift->id)
                ->where('payment_method', 'cash')
                ->where('order_status', \App\Enums\OrderStatus::Paid->value)
                ->sum('order_amount');
        }

        return view('livewire.shift-manager', [
            'cashSales' => $cashSales,
            'expectedCash' => $this->activeShift ? ($this->activeShift->opening_balance + $cashSales) : 0,
        ]);
    }
}
