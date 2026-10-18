<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CashDrawerMovement;
use App\Models\Payment;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ShiftService
{
    /**
     * [D2-BE-008] Membuka shift untuk satu user secara atomik.
     * Invarian satu-shift-aktif-per-user ditegakkan oleh unique constraint
     * `shifts_one_open_per_user_unique` (penjaga otoritatif), ditambah
     * pengecekan exists sebagai UX fail-fast.
     *
     * @return Shift|null shift yang baru dibuka, atau null bila sudah ada shift aktif.
     */
    public function openShift(int $userId, int $openingBalance): ?Shift
    {
        $user = User::withoutGlobalScopes()->find($userId);
        if ($user && $user->pin_hash === null) {
            throw new \DomainException('Kasir wajib menyetel PIN Kasir sebelum dapat membuka shift.');
        }

        return DB::transaction(function () use ($userId, $openingBalance) {
            $existing = Shift::where('user_id', $userId)
                ->where('status', 'open')
                ->lockForUpdate()
                ->first();
            if ($existing) {
                return null;
            }

            try {
                return Shift::create([
                    'user_id' => $userId,
                    'opening_balance' => $openingBalance,
                    'status' => 'open',
                    'opened_at' => now(),
                ]);
            } catch (QueryException $e) {
                // Race kedua: unique constraint menolak shift aktif kedua.
                if (! str_contains($e->getMessage(), 'shifts_one_open_per_user_unique')) {
                    throw $e;
                }

                return null;
            }
        });
    }

    /**
     * [D2-BE-008] Mencatat CashDrawerMovement (cash in / cash out) secara atomik
     * di dalam shift aktif milik user. Mengunci baris shift agar rekap kas
     * tidak berubah di bawah kaki pencatat.
     */
    public function recordCashMovement(int $userId, int $shiftId, string $type, int $amount, string $category, string $reason, ?string $receiptReference = null): ?CashDrawerMovement
    {
        if (! in_array($type, ['cash_in', 'cash_out'], true)) {
            throw new \InvalidArgumentException("Tipe pergerakan kas tidak dikenal: {$type}");
        }
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Nominal pergerakan kas harus positif.');
        }

        return DB::transaction(function () use ($userId, $shiftId, $type, $amount, $category, $reason, $receiptReference) {
            $shift = Shift::where('id', $shiftId)
                ->where('user_id', $userId)
                ->where('status', 'open')
                ->lockForUpdate()
                ->first();
            if (! $shift) {
                return null;
            }

            return CashDrawerMovement::create([
                'tenant_id' => $shift->tenant_id,
                'store_id' => $shift->store_id,
                'shift_id' => $shift->id,
                'type' => $type,
                'category' => $category,
                'amount' => $amount,
                'reason' => $reason,
                'receipt_reference' => $receiptReference,
                'created_by' => $userId,
            ]);
        });
    }

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
