<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CashDrawerMovement;
use App\Models\Payment;
use App\Models\Shift;
use Illuminate\Support\Facades\DB;

class ShiftService
{
    /**
     * Hitung ringkasan kas (penjualan tunai, cash-in, cash-out, dan kas yang diharapkan).
     *
     * @return array{cash_sales: int, cash_in: int, cash_out: int, expected_cash: int}
     */
    public function getCashSummary(Shift $shift): array
    {
        $cashSales = (int) Payment::join('orders', 'orders.id', '=', 'payments.order_id')
            ->where('orders.shift_id', $shift->id)
            ->where('payments.payment_method', 'cash')
            ->whereIn('payments.status', ['captured', 'voided'])
            ->sum(DB::raw("CASE WHEN payments.status = 'captured' THEN payments.amount ELSE -payments.amount END"));

        $cashIn = (int) CashDrawerMovement::where('shift_id', $shift->id)
            ->where('type', 'cash_in')
            ->sum('amount');

        $cashOut = (int) CashDrawerMovement::where('shift_id', $shift->id)
            ->where('type', 'cash_out')
            ->sum('amount');

        $expectedCash = (int) $shift->opening_balance + $cashSales + $cashIn - $cashOut;

        return [
            'cash_sales' => $cashSales,
            'cash_in' => $cashIn,
            'cash_out' => $cashOut,
            'expected_cash' => $expectedCash,
        ];
    }

    /**
     * Menutup shift aktif secara atomik dan mengunci baris shift untuk update.
     */
    public function closeShift(int $userId, int $closingBalance, ?string $notes = null): ?Shift
    {
        return DB::transaction(function () use ($userId, $closingBalance, $notes) {
            $shift = Shift::where('user_id', $userId)
                ->where('status', 'open')
                ->lockForUpdate()
                ->first();

            if (! $shift) {
                return null;
            }

            $summary = $this->getCashSummary($shift);

            $shift->update([
                'closing_balance' => $closingBalance,
                'expected_cash' => $summary['expected_cash'],
                'cash_difference' => $closingBalance - $summary['expected_cash'],
                'notes' => $notes,
                'status' => 'closed',
                'closed_at' => now(),
            ]);

            return $shift;
        });
    }
}
